<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\ConversationDeclaringPropagationPolicy;
use Illuminate\Support\Facades\Artisan;

/**
 * Durable counterpart to ConversationScopeIsolationTest. The durable branch
 * advancer reloads the run's RunContext from persistence
 * (DatabaseDurableRunStore / DatabaseContextStore) before it freezes a snapshot,
 * so this is the test that proves the conversation handle bound via
 * {@see RunContext::withConversationId()} survives the real durable
 * serialize → store → reload cycle — not just the in-memory queue payload
 * roundtrip the unit tests cover. The conversation id rides in the run metadata
 * map, which the durable stores carry wholesale; if that ever regressed, the
 * Conversation scope would resolve to null on recovery and the seeded entry
 * would vanish from the frozen snapshot.
 */
pest()->group('compliance');

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');
    config()->set('swarm.memory.propagation_policy', ConversationDeclaringPropagationPolicy::class);

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(DurableSwarmManager::class);

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(fn (): string => 'research-out');
    FakeWriter::fake(fn (): string => 'writer-out');
    FakeEditor::fake(fn (): string => 'editor-out');
});

/**
 * Flatten the plain-data entries of every frozen snapshot for a run, read back
 * from the real persistence recorder (entries are arrays, not MemoryEntry).
 *
 * @return array<int, array<string, mixed>>
 */
function conversationDurableFrozenEntries(string $runId): array
{
    $entries = [];

    /** @var array<int, MemorySnapshot> $snapshots */
    $snapshots = app(SnapshotsMemory::class)->allForRun($runId);

    foreach ($snapshots as $snapshot) {
        foreach ($snapshot->entries as $entry) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

test('a conversation id bound to a durable run survives recovery and surfaces the right conversation', function () {
    // Bind the run to conv-1 and dispatch it durably; the advancer will reload
    // the context from the durable store before freezing, so the conversation id
    // must survive that round-trip for the Conversation scope to resolve.
    $response = FakeParallelSwarm::make()->dispatchDurable(
        RunContext::from('task', 'conv-durable-run')->withConversationId('conv-1'),
    );
    $manager = app(DurableSwarmManager::class);

    expect($response->runId)->toBe('conv-durable-run');

    // Two conversations seeded; only conv-1 is bound to this run.
    app(SwarmMemory::class)->put(MemoryScope::Conversation, 'conv-1', 'preference', 'concise');
    app(SwarmMemory::class)->put(MemoryScope::Conversation, 'conv-2', 'preference', 'verbose');

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);          // fan out branches
    (new AdvanceDurableBranch($response->runId, 'parallel:0'))->handle($manager); // freeze branch snapshot

    $entries = conversationDurableFrozenEntries($response->runId);
    $scopes = array_map(static fn (array $entry): string => $entry['scope'], $entries);
    $values = array_map(static fn (array $entry): mixed => $entry['value'], $entries);

    // conv-1's entry surfaces under the Conversation scope on the durable path;
    // conv-2's never does. A regression that dropped conversation_id on the
    // durable round-trip would make 'concise' vanish here.
    expect($scopes)->toContain(MemoryScope::Conversation->value)
        ->and($values)->toContain('concise')
        ->and($values)->not->toContain('verbose');
});

test('a durable run with no conversation id skips the Conversation scope on recovery', function () {
    // The mirror of the surfacing case: with no conversation handle bound, the
    // reloaded context resolves the Conversation scope_id to null and the
    // advancer skips it, even though the policy declares it.
    $response = FakeParallelSwarm::make()->dispatchDurable('conv-durable-none');
    $manager = app(DurableSwarmManager::class);

    app(SwarmMemory::class)->put(MemoryScope::Conversation, 'conv-1', 'preference', 'concise');
    app(SwarmMemory::class)->put(MemoryScope::Run, $response->runId, 'run-note', 'visible');

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    (new AdvanceDurableBranch($response->runId, 'parallel:0'))->handle($manager);

    $entries = conversationDurableFrozenEntries($response->runId);
    $scopes = array_map(static fn (array $entry): string => $entry['scope'], $entries);
    $values = array_map(static fn (array $entry): mixed => $entry['value'], $entries);

    expect($scopes)->not->toContain(MemoryScope::Conversation->value)
        ->and($values)->toContain('visible')
        ->and($values)->not->toContain('concise');
});
