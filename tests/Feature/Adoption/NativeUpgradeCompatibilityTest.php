<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

function nativeUpgradeFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/Upgrade/v0263.json'), true, flags: JSON_THROW_ON_ERROR);
}

function nativeUpgradeJob(string $name): object
{
    return unserialize(base64_decode(nativeUpgradeFixture()['jobs'][$name], true), ['allowed_classes' => [
        InvokeSwarm::class, BroadcastSwarm::class, AdvanceDurableSwarm::class,
        AdvanceDurableBranch::class, ResumeQueuedHierarchicalSwarm::class, Channel::class,
    ]]);
}

function nativeUpgradeUnavailableUsage(): array
{
    return array_fill_keys(['input_tokens', 'output_tokens', 'prompt_tokens', 'completion_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'], null);
}

function nativeUpgradeLegacyResult(): array
{
    return [
        'format_version' => 1,
        'status' => 'unavailable',
        'reasons' => ['legacy'],
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 00:01:00 UTC');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('v', 32)));
    app()->forgetInstance('encrypter');
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.recovery.grace_seconds', 0);
    config()->set('queue.default', 'durable-test');
    config()->set('broadcasting.default', 'null');
    Http::preventStrayRequests();
    $schema = DB::connection()->getSchemaBuilder();
    $schema->disableForeignKeyConstraints();
    try {
        foreach (nativeUpgradeFixture()['tables'] as $table => $rows) {
            DB::table($table)->insert($rows);
        }
    } finally {
        $schema->enableForeignKeyConstraints();
    }
    FakeResearcher::fake([new AgentResponse('new-research', 'new-researched', new TextUsage(20, 5, 2, 0, 1), new Meta('fake', 'native'))]);
    FakeWriter::fake([new AgentResponse('new-writer', 'new-written', new TextUsage(30, 7, null, 0, 2), new Meta('fake', 'native'))]);
    FakeEditor::fake([new AgentResponse('new-editor', 'new-edited', new TextUsage(40, 9, 3, 0, 3), new Meta('fake', 'native'))]);
    FakeHierarchicalCoordinator::fake();
});

afterEach(fn () => Carbon::setTestNow());

it('pins the immutable v0263 original writer and preserves the v025 fixture', function () {
    $fixture = nativeUpgradeFixture();
    expect(hash_file('sha256', __DIR__.'/Fixtures/Upgrade/v0263.json'))->toBe('439ead223213a62f022619f95df7e400d7c61bec693c080c5d0ff0cfa33fdd50')
        ->and($fixture['swarm'])->toBe('38b3b649f3416a31c8d1f39ecefd77446e676a3c')
        ->and($fixture['lock_sha256'])->toBe(hash_file('sha256', __DIR__.'/Fixtures/Upgrade/v0263-producer.lock'))
        ->and($fixture['dependencies']['laravel/ai'])->toBe([
            'version' => 'v0.11.2', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/ai.git', 'reference' => 'ee2c5162838d440c4e2e629ea93c8c87e838eaed'],
        ])
        ->and(hash_file('sha256', __DIR__.'/Fixtures/Upgrade/v025.json'))->toBe('b6f47ba4f1a08cd714b80b5e9fc695b3f28d265aea1dd31cb8d7db2928b58f73');
});

it('reads completed v0263 history without renaming legacy counters or rewriting sealed evidence', function () {
    $fixture = nativeUpgradeFixture();
    $before = DB::table('swarm_run_histories')->where('run_id', 'v0263-completed')->first();
    $history = app(RunHistoryStore::class)->find('v0263-completed');
    $expected = $fixture['histories']['completed'];
    foreach ($expected['steps'] as &$step) {
        $offset = array_search('artifacts', array_keys($step), true);
        $step = array_merge(
            array_slice($step, 0, $offset, true),
            ['native_result_status' => 'unavailable', 'native_result' => nativeUpgradeLegacyResult()],
            array_slice($step, $offset, null, true),
        );
    }
    unset($step);
    expect($history)->toBe($expected)
        ->and($history['usage'])->toBe(['prompt_tokens' => 41, 'completion_tokens' => 15, 'cache_write_input_tokens' => 2, 'cache_read_input_tokens' => 6, 'reasoning_tokens' => 4])
        ->and($history['output'])->toBe('old-edited')
        ->and(app(ContextStore::class)->find('v0263-completed')['input'])->toBe('old completed input')
        ->and($before->output)->toStartWith('sw0:')
        ->and(DB::table('swarm_run_histories')->where('run_id', 'v0263-completed')->first())->toEqual($before);
    FakeResearcher::assertNeverPrompted();
    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
    Http::assertNothingSent();
});

it('continues frozen v0263 cursors without repeating completed legacy work', function (string $status) {
    $id = 'v0263-'.$status;
    $manager = app(DurableSwarmManager::class);
    $historyStore = app(RunHistoryStore::class);
    $oldStep = $historyStore->find($id)['steps'][0];
    expect($manager->find($id)['status'])->toBe($status)
        ->and($manager->find($id)['next_step_index'])->toBe(1)
        ->and($oldStep['output'])->toBe('old-researched')
        ->and($oldStep['metadata']['usage']['prompt_tokens'])->toBe(11)
        ->and(app(ContextStore::class)->find($id)['input'])->toBe('old '.$status.' input')
        ->and(DB::table('swarm_contexts')->where('run_id', $id)->value('input'))->toStartWith('sw0:');
    if ($status === 'waiting') {
        expect($manager->signal($id, 'operator-release', ['approved' => true], 'upgrade-release')->accepted)->toBeTrue();
    }
    if ($status === 'running') {
        $manager->recover(runId: $id);
        expect($manager->find($id)['recovery_count'])->toBe(1);
    }
    app()->call([nativeUpgradeJob($status), 'handle']);
    expect($manager->find($id)['next_step_index'])->toBe(2);
    app()->call([new AdvanceDurableSwarm($id, 2), 'handle']);
    $history = $historyStore->find($id);
    expect($manager->find($id)['status'])->toBe('completed')
        ->and($history['output'])->toBe('new-edited')
        ->and($history['steps'])->toHaveCount(3)
        ->and($history['steps'][0])->toBe($oldStep)
        ->and($history['steps'][1]['metadata']['usage'])->toBe((new TextUsage(30, 7, null, 0, 2))->toArray())
        ->and($history['usage'])->toBe(nativeUpgradeUnavailableUsage());
    FakeResearcher::assertNeverPrompted();
    FakeWriter::assertPromptedTimes(1);
    FakeWriter::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'old-researched'));
    FakeEditor::assertPromptedTimes(1);
    Http::assertNothingSent();
})->with(['pending', 'waiting', 'running']);

it('executes frozen v0263 invoke and broadcast jobs through native1 handlers', function (string $job, string $id) {
    $payload = nativeUpgradeJob($job);
    expect($payload->task['run_id'])->toBe($id)->and($payload->enqueuedAtMs)->toBe(1790121600000);
    app()->call([$payload, 'handle']);
    $history = app(RunHistoryStore::class)->find($id);
    expect($history['status'])->toBe('completed')->and($history['output'])->toBe('new-edited')
        ->and($history['usage']['input_tokens'])->toBe(90)
        ->and($history['usage']['cache_read_input_tokens'])->toBeNull();
    FakeResearcher::assertPromptedTimes(1);
    FakeWriter::assertPromptedTimes(1);
    FakeEditor::assertPromptedTimes(1);
    Http::assertNothingSent();
})->with([['invoke', 'v0263-queue'], ['broadcast', 'v0263-broadcast']]);

it('joins frozen v0263 durable parallel branches while retaining completed legacy branch evidence', function () {
    $id = 'v0263-parallel';
    $store = app(DurableRunStore::class);
    $oldBranch = $store->branchesFor($id, 'parallel')[0];
    expect($oldBranch['status'])->toBe('completed')->and($oldBranch['usage']['prompt_tokens'])->toBe(11);
    app()->call([nativeUpgradeJob('parallel_branch'), 'handle']);
    app()->call([new AdvanceDurableBranch($id, 'parallel:2'), 'handle']);
    app()->call([nativeUpgradeJob('parallel_join'), 'handle']);
    $history = app(RunHistoryStore::class)->find($id);
    expect($history['status'])->toBe('completed')->and($history['usage'])->toBe(nativeUpgradeUnavailableUsage())
        ->and($history['output'])->toBe("old-researched\n\nnew-written\n\nnew-edited")
        ->and($store->branchesFor($id, 'parallel')[0])->toBe($oldBranch);
    FakeResearcher::assertNeverPrompted();
    FakeWriter::assertPromptedTimes(1);
    FakeEditor::assertPromptedTimes(1);
    Http::assertNothingSent();
});

it('resumes the frozen v0263 coordinated queue join without repeating its coordinator or completed worker', function () {
    $id = 'v0263-join';
    $store = app(DurableRunStore::class);
    $oldBranch = $store->branchesFor($id)[0];
    $oldSteps = app(RunHistoryStore::class)->find($id)['steps'];
    expect($oldBranch['status'])->toBe('completed')->and($oldBranch['usage']['prompt_tokens'])->toBe(13);
    app()->call([nativeUpgradeJob('branch'), 'handle']);
    app()->call([nativeUpgradeJob('resume'), 'handle']);
    $history = app(RunHistoryStore::class)->find($id);
    expect($store->find($id)['status'])->toBe('completed')
        ->and($history['output'])->toBe('new-edited')
        ->and($history['usage'])->toBe(nativeUpgradeUnavailableUsage())
        ->and(array_slice($history['steps'], 0, 2))->toBe($oldSteps)
        ->and($store->branchesFor($id)[0])->toBe($oldBranch);
    FakeHierarchicalCoordinator::assertNeverPrompted();
    FakeWriter::assertNeverPrompted();
    FakeEditor::assertPromptedTimes(1);
    Http::assertNothingSent();
});

it('replays frozen v0263 stream identity and legacy usage without provider calls or storage rewrites', function () {
    $before = DB::table('swarm_stream_events')->where('run_id', 'v0263-stream')->orderBy('sequence')->get()->all();
    $events = iterator_to_array(app(StreamEventStore::class)->events('v0263-stream'));
    expect($events)->toHaveCount(count($before));
    foreach ($events as $index => $event) {
        $raw = json_decode($before[$index]->payload, true, flags: JSON_THROW_ON_ERROR);
        if (isset($raw['citation_evidence'])) {
            expect($raw['citation_evidence'])->toStartWith('sw0:');
            $evidence = json_decode(app('encrypter')->decryptString(substr($raw['citation_evidence'], 4)), true, flags: JSON_THROW_ON_ERROR);
            unset($raw['citation_evidence']);
            $raw = array_merge($raw, $evidence);
        }
        $current = $event->toArray();
        if ($event instanceof SwarmToolResult) {
            expect($event->preliminary)->toBeFalse()->and($event->denied)->toBeTrue()
                ->and($event->toolResult->denied)->toBeTrue()->and($event->successful)->toBeFalse();
            unset($current['preliminary'], $current['denied']);
        }
        // Ordinary historical streams have no attempt; the codec now omits its null field.
        expect($raw['attempt_epoch'] ?? null)->toBeNull()
            ->and($current['attempt_epoch'] ?? null)->toBeNull();
        unset($raw['attempt_epoch'], $current['attempt_epoch']);
        if ($event instanceof SwarmStepEnd && ! isset($raw['native_result'])) {
            expect($current['native_result'])->toBe(nativeUpgradeLegacyResult());
            unset($current['native_result']);
        }
        expect($current)->toEqual($raw);
    }
    expect(app(RunHistoryStore::class)->find('v0263-stream')['usage'])->toBe(nativeUpgradeFixture()['histories']['stream']['usage'])
        ->and(DB::table('swarm_stream_events')->where('run_id', 'v0263-stream')->orderBy('sequence')->get()->all())->toEqual($before);
    FakeResearcher::assertNeverPrompted();
    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
    Http::assertNothingSent();
});
