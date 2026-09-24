<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeConversationUpgrade\CustomStoreContract;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeConversationUpgrade\NativeConversationFixture as Fixture;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Ai\Storage\StoredMessage;

uses()->group('native-conversation-upgrade');

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->nativeUpgrade = new Fixture;
    $requested = getenv('DB_CONNECTION') ?: 'sqlite';
    if (getenv('SWARM_NATIVE_UPGRADE_REAL_DB') === '1') {
        expect($requested)->toBeIn(['mysql', 'pgsql']);
    }
    expect($this->nativeUpgrade->native()->getDriverName())->toBe($requested)
        ->and($this->nativeUpgrade->default()->getDriverName())->toBe('sqlite')
        ->and($this->nativeUpgrade->native()->getPdo())->not->toBe($this->nativeUpgrade->default()->getPdo());
});

afterEach(function (): void {
    $this->nativeUpgrade?->cleanup();
});

function assertNativeUpgradeIsolation(Fixture $fixture): void
{
    expect($fixture->native()->getSchemaBuilder()->hasTable(Fixture::MESSAGES))->toBeTrue()
        ->and($fixture->native()->getSchemaBuilder()->hasTable(Fixture::CONVERSATIONS))->toBeTrue()
        ->and($fixture->default()->getSchemaBuilder()->hasTable(Fixture::MESSAGES))->toBeFalse()
        ->and($fixture->default()->getSchemaBuilder()->hasTable(Fixture::CONVERSATIONS))->toBeFalse()
        ->and($fixture->default()->table('c2_sentinel')->pluck('value')->all())->toBe(['untouched default'])
        ->and(DB::getDefaultConnection())->toBe('c2_default');
}

function assertNativeUpgradeSchema(Fixture $fixture): void
{
    $schema = $fixture->native()->getSchemaBuilder();
    expect($schema->hasColumns(Fixture::MESSAGES, ['steps', 'status']))->toBeTrue();
    foreach (['tool_calls', 'tool_results', 'approval_state'] as $column) {
        expect($schema->hasColumn(Fixture::MESSAGES, $column))->toBeFalse();
    }
    $index = collect($schema->getIndexes(Fixture::MESSAGES))->firstWhere('name', 'participant_index');
    expect($index['columns'])->toBe(['participant_type', 'participant_id', 'agent']);
}

it('installs the actual native schema through Migrator on the isolated configured connection', function (): void {
    $fixture = $this->nativeUpgrade;
    $fixture->fresh();
    assertNativeUpgradeIsolation($fixture);
    assertNativeUpgradeSchema($fixture);
    CustomStoreContract::verify('c2_native', Fixture::CONVERSATIONS, Fixture::MESSAGES);
    assertNativeUpgradeIsolation($fixture);
});

it('retains old native row meaning and identities through the executable application migration', function (): void {
    $fixture = $this->nativeUpgrade;
    $fixture->legacy();
    $fixture->seed();
    $before = $fixture->snapshot();
    $fixture->upgrade();
    assertNativeUpgradeSchema($fixture);
    assertNativeUpgradeIsolation($fixture);
    $after = $fixture->snapshot();
    expect($after['conversations'])->toBe($before['conversations'])
        ->and(array_column($after['messages'], 'id'))->toBe(array_column($before['messages'], 'id'));
    $converted = [];
    foreach ($after['messages'] as $index => $row) {
        foreach (['id', 'conversation_id', 'participant_type', 'participant_id', 'agent', 'role', 'content', 'attachments', 'usage', 'created_at', 'updated_at'] as $field) {
            expect($row[$field])->toBe($before['messages'][$index][$field]);
        }
        $stored = StoredMessage::fromArray($row);
        expect($stored->status)->toBe(MessageStatus::Completed)
            ->and($stored->meta['custom'])->toBe('保持');
        $converted[$row['id']] = $stored;
    }
    expect($converted[Fixture::id(1)]->steps)->toBe([])
        ->and($converted[Fixture::id(2)]->toolResults()[0]['result'])->toBe('first')
        ->and($converted[Fixture::id(3)]->toolResults()[0]['result'])->toBe('second')
        ->and($converted[Fixture::id(4)]->toolResults()[0])->toMatchArray(['denied' => true, 'failed' => false])
        ->and($converted[Fixture::id(5)]->toolResults()[0])->toMatchArray(['denied' => false, 'failed' => true])
        ->and($converted[Fixture::id(6)]->toolResults()[0]['result'])->toBe('later answer')
        ->and($converted[Fixture::id(7)]->toolResults())->toBe([])
        ->and($converted[Fixture::id(8)]->toolResults()[0])->toBe([
            'id' => 'edited', 'name' => 'ExecutedTool', 'arguments' => ['value' => 'executed'],
            'result_id' => 'executed-result', 'result' => 'edited answer',
        ])
        ->and($converted[Fixture::id(8)]->steps[1]['reasoning'])->toBe('考え')
        ->and($converted[Fixture::id(8)]->meta)->toBe(['custom' => '保持'])
        ->and($converted[Fixture::id(9)]->toolResults())->toHaveCount(1)
        ->and($converted[Fixture::id(9)]->toolResults()[0]['result'])->toBeNull()
        ->and($converted[Fixture::id(10)]->toolResults()[0]['result'])->toBe(str_repeat('y', 45000).'終')
        ->and($converted[Fixture::id(11)]->toolCalls())->toBe([]);
    $store = new DatabaseConversationStore('c2_native');
    $history = $store->paginateConversationMessages(Fixture::id(1), 30)->items();
    expect(array_map(fn ($item) => $item->id, $history))->toBe(array_reverse(array_column($before['messages'], 'id')));
});

it('refuses an unresolved pending turn beyond the first conversation batch before any DDL', function (): void {
    $fixture = $this->nativeUpgrade;
    $fixture->legacy();
    $fixture->seed();
    for ($number = 20; $number <= 125; $number++) {
        $fixture->native()->table(Fixture::MESSAGES)->insert(Fixture::row($number, [
            'conversation_id' => Fixture::id($number),
            'approval_state' => $number === 125 ? '{"pending":{"approval":"operator decision"}}' : null,
        ]));
    }
    $before = $fixture->snapshot();
    $schema = $fixture->native()->getSchemaBuilder()->getColumns(Fixture::MESSAGES);
    expect(fn () => $fixture->upgrade())->toThrow(RuntimeException::class, 'unresolved or invalid approval state');
    expect($fixture->snapshot())->toBe($before)
        ->and($fixture->native()->getSchemaBuilder()->getColumns(Fixture::MESSAGES))->toBe($schema)
        ->and($fixture->default()->table('c2_migrations')->count())->toBe(1);
});

it('refuses malformed or ambiguous old evidence before schema and row changes', function (string $case): void {
    $fixture = $this->nativeUpgrade;
    $fixture->legacy();
    $fixture->seed();
    $table = $fixture->native()->table(Fixture::MESSAGES);
    if ($case === 'malformed') {
        $table->where('id', Fixture::id(8))->update(['approval_state' => '{not-json']);
    } else {
        $call = json_encode([['id' => 'ambiguous', 'name' => 'tool', 'arguments' => []]], JSON_THROW_ON_ERROR);
        $table->insert(Fixture::row(12, ['tool_calls' => $call]));
        $table->insert(Fixture::row(13, ['tool_calls' => $call]));
        $table->insert(Fixture::row(14, ['tool_results' => '[{"id":"ambiguous","name":"tool","arguments":[],"result":"unknown owner"}]']));
    }
    $before = $fixture->snapshot();
    expect(fn () => $fixture->upgrade())->toThrow(RuntimeException::class);
    expect($fixture->snapshot())->toBe($before)
        ->and($fixture->native()->getSchemaBuilder()->hasColumn(Fixture::MESSAGES, 'steps'))->toBeFalse();
})->with(['malformed', 'ambiguous']);

it('fails closed before DDL when one conversation exceeds the documented safety ceiling', function (): void {
    $fixture = $this->nativeUpgrade;
    $fixture->legacy();
    $fixture->seed();
    $rows = [];
    for ($number = 12; $number <= 10_001; $number++) {
        $rows[] = Fixture::row($number);
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        $fixture->native()->table(Fixture::MESSAGES)->insert($chunk);
    }
    $before = $fixture->snapshot();
    expect(fn () => $fixture->upgrade())->toThrow(RuntimeException::class, '10,000-message safety ceiling');
    expect($fixture->snapshot())->toBe($before)
        ->and($fixture->native()->getSchemaBuilder()->hasColumn(Fixture::MESSAGES, 'steps'))->toBeFalse();
});

it('refuses unsupported legacy payloads without erasing their evidence', function (array $values): void {
    $fixture = $this->nativeUpgrade;
    $fixture->legacy();
    $fixture->native()->table(Fixture::MESSAGES)->insert(Fixture::row(1, $values));
    $before = $fixture->snapshot();
    expect(fn () => $fixture->upgrade())->toThrow(RuntimeException::class);
    expect($fixture->snapshot())->toBe($before)
        ->and($fixture->native()->getSchemaBuilder()->hasColumn(Fixture::MESSAGES, 'steps'))->toBeFalse();
})->with([
    'unknown state' => [['approval_state' => '{}']],
    'invalid pending map' => [['approval_state' => '{"pending":"unknown"}']],
    'unsupported role' => [['role' => 'tool']],
    'user tool evidence' => [['role' => 'user', 'tool_calls' => '[{"id":"a","name":"tool","arguments":[]}]']],
    'not a list' => [['tool_calls' => '{"a":{"id":"a"}}']],
    'missing identity' => [['tool_calls' => '[{"name":"tool","arguments":[]}]']],
    'bad arguments' => [['tool_calls' => '[{"id":"a","name":"tool","arguments":"unknown"}]']],
    'truthy flag' => [['tool_calls' => '[{"id":"a","name":"tool","arguments":[],"denied":"false"}]']],
    'absent result field' => [['tool_results' => '[{"id":"a","name":"tool","arguments":[]}]']],
    'invalid meta' => [['meta' => 'null']],
    'invalid reasoning' => [['meta' => '{"reasoning":[]}']],
]);

it('preserves the old row-local last-result meaning for duplicate IDs within a row', function (): void {
    $fixture = $this->nativeUpgrade;
    $fixture->legacy();
    $call = ['id' => 'same', 'name' => 'tool', 'arguments' => []];
    $fixture->native()->table(Fixture::MESSAGES)->insert(Fixture::row(1, [
        'tool_calls' => json_encode([$call, $call], JSON_THROW_ON_ERROR),
        'tool_results' => json_encode([array_merge($call, ['result' => 'earlier']), array_merge($call, ['result' => 'last'])], JSON_THROW_ON_ERROR),
    ]));
    $fixture->upgrade();
    $stored = StoredMessage::fromArray((array) $fixture->native()->table(Fixture::MESSAGES)->sole());
    expect(array_column($stored->toolResults(), 'result'))->toBe(['last', 'last']);
});

it('refuses repeat or partial native conversion rather than treating it as a retry', function (): void {
    $fixture = $this->nativeUpgrade;
    $fixture->fresh();
    $before = $fixture->snapshot();
    $columns = $fixture->native()->getSchemaBuilder()->getColumns(Fixture::MESSAGES);
    expect(fn () => $fixture->upgrade())->toThrow(RuntimeException::class, 'Expected the complete old native schema');
    expect($fixture->snapshot())->toBe($before)
        ->and($fixture->native()->getSchemaBuilder()->getColumns(Fixture::MESSAGES))->toBe($columns);
});

it('converts only the configured native database while preserving distinguishable default decoys', function (): void {
    $fixture = $this->nativeUpgrade;
    $fixture->legacy();
    $fixture->seed();
    // Execute the frozen old schema on the isolated default solely to seed decoys.
    (require __DIR__.'/Fixtures/NativeConversationUpgrade/2026_01_11_000001_create_agent_conversations_table.php')->up();
    $fixture->default()->table(Fixture::MESSAGES)->insert(Fixture::row(77, ['content' => 'default decoy, unchanged']));
    $decoy = $fixture->snapshot('c2_default');
    $decoyColumns = $fixture->default()->getSchemaBuilder()->getColumns(Fixture::MESSAGES);
    $fixture->upgrade();
    expect($fixture->snapshot('c2_default'))->toBe($decoy)
        ->and($fixture->default()->getSchemaBuilder()->getColumns(Fixture::MESSAGES))->toBe($decoyColumns)
        ->and($fixture->native()->table(Fixture::MESSAGES)->whereNotNull('steps')->count())->toBe(11);
    assertNativeUpgradeSchema($fixture);
});

it('rehearses coordinated old schema and row restoration after a new-format write', function (): void {
    $fixture = $this->nativeUpgrade;
    $fixture->legacy();
    $fixture->seed();
    $backup = $fixture->snapshot();
    $oldColumns = $fixture->native()->getSchemaBuilder()->getColumns(Fixture::MESSAGES);
    $oldIndexes = $fixture->native()->getSchemaBuilder()->getIndexes(Fixture::MESSAGES);
    $fixture->upgrade();
    $store = new DatabaseConversationStore('c2_native');
    $store->storeUserMessage(Fixture::id(1), 'fixture-user', 17, 'FixtureAgent', new UserMessage('post-backup write'));
    expect($fixture->native()->table(Fixture::MESSAGES)->count())->toBe(12);
    $migration = require dirname(__DIR__, 3).'/docs/examples/2026_09_23_000001_upgrade_native_ai_conversation_messages.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'cannot be reconstructed');
    $fixture->restore($backup);
    expect($fixture->snapshot())->toBe($backup)
        ->and($fixture->native()->getSchemaBuilder()->getColumns(Fixture::MESSAGES))->toBe($oldColumns)
        ->and($fixture->native()->getSchemaBuilder()->getIndexes(Fixture::MESSAGES))->toBe($oldIndexes);
});

it('authorizes before native inspection or application invocation and refuses missing ownership support', function (): void {
    $authorize = require dirname(__DIR__, 3).'/docs/examples/with-authorized-native-conversation.php';
    $denied = Mockery::mock(ConversationStore::class, VerifiesConversationOwnership::class);
    $denied->shouldReceive('conversationBelongsTo')->once()->with('untrusted-id', 'user', 17)->andReturnFalse();
    $denied->shouldNotReceive('getLatestConversationMessages');
    $operations = [];
    $operation = function (ConversationStore $store, string $id) use (&$operations) {
        $operations[] = 'read-or-invoke';

        return $store->getLatestConversationMessages($id, 10);
    };
    expect(fn () => $authorize($denied, 'untrusted-id', 'user', 17, $operation))->toThrow(AuthorizationException::class);
    $unsupported = Mockery::mock(ConversationStore::class);
    $unsupported->shouldNotReceive('getLatestConversationMessages');
    expect(fn () => $authorize($unsupported, 'untrusted-id', 'user', 17, $operation))->toThrow(AuthorizationException::class);
    expect($operations)->toBe([]);
    $allowed = Mockery::mock(ConversationStore::class, VerifiesConversationOwnership::class);
    $allowed->shouldReceive('conversationBelongsTo')->once()->with('owned', 'user', 17)->andReturnTrue()->ordered();
    $allowed->shouldReceive('getLatestConversationMessages')->once()->with('owned', 10)->andReturn(collect(['retained']))->ordered();
    expect($authorize($allowed, 'owned', 'user', 17, $operation)->all())->toBe(['retained'])
        ->and($operations)->toBe(['read-or-invoke']);
});
