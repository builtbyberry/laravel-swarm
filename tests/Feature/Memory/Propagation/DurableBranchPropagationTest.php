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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRestrictiveParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\RestrictivePropagationPolicy;
use Illuminate\Support\Facades\Artisan;

/**
 * The durable parallel-branch advancer (DurableBranchAdvancer) is the fourth
 * runner. Unlike the live hierarchical-parallel path it resolves the concrete
 * branch agent before snapshotting, so it gathers the Agent scope too. These
 * tests drive a real durable run on the database persistence driver and read the
 * frozen snapshot back from the real DatabaseMemorySnapshotRecorder — proving
 * what actually froze to disk, the same observation channel ReplayDeterminismTest
 * uses. (Live runners use the in-memory recorder spy; the two channels are kept
 * in separate files on purpose.)
 */
pest()->group('compliance');

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

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
 * Flatten the plain-data entries of every frozen snapshot for a run. Read back
 * from persistence the entries are arrays (scope/scope_id/key/value), not
 * MemoryEntry objects.
 *
 * @return array<int, array<string, mixed>>
 */
function frozenEntriesForRun(string $runId): array
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

test('the default policy presents Run-only on the durable branch advancer', function () {
    $response = FakeParallelSwarm::make()->dispatchDurable('durable-default-propagation');
    $manager = app(DurableSwarmManager::class);

    // A Run-scoped note (kept) and a Swarm-scoped note (dropped by the default
    // Run-only policy) seeded before the branch advances.
    app(SwarmMemory::class)->put(MemoryScope::Run, $response->runId, 'note', 'run-value');
    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeParallelSwarm::class, 'shared-note', 'swarm-value');

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);          // fan out branches
    (new AdvanceDurableBranch($response->runId, 'parallel:0'))->handle($manager); // freeze branch snapshot

    $entries = frozenEntriesForRun($response->runId);

    expect($entries)->not->toBeEmpty();
    foreach ($entries as $entry) {
        expect($entry['scope'])->toBe(MemoryScope::Run->value);
    }
    $keys = array_map(static fn (array $entry): string => $entry['key'], $entries);
    expect($keys)->not->toContain('shared-note');
});

test('the restrictive policy keeps only the allow-listed key on the durable branch advancer', function () {
    $response = FakeRestrictiveParallelSwarm::make()->dispatchDurable('durable-restrictive-propagation');
    $manager = app(DurableSwarmManager::class);

    $memory = app(SwarmMemory::class);
    // Candidates across all three gatherable scopes. Both halves of the
    // Agent-scope behaviour are pinned: an allow-listed Agent entry (which
    // exists ONLY in Agent scope) must surface — positively proving the branch
    // advancer gathers the Agent scope because it resolves the concrete agent
    // (parallel:0 is FakeResearcher) — while a disallowed Agent entry is dropped
    // by the policy filter. If gathering regressed to skip Agent scope,
    // 'agent-keep' would vanish and this test would fail.
    $memory->put(MemoryScope::Run, $response->runId, RestrictivePropagationPolicy::ALLOWED_KEY, 'keep-me');
    $memory->put(MemoryScope::Run, $response->runId, 'disallowed-note', 'drop-me-run');
    $memory->put(MemoryScope::Agent, FakeResearcher::class, RestrictivePropagationPolicy::ALLOWED_KEY, 'agent-keep');
    $memory->put(MemoryScope::Agent, FakeResearcher::class, 'disallowed-agent-note', 'drop-me-agent');
    $memory->put(MemoryScope::Swarm, FakeRestrictiveParallelSwarm::class, 'shared-note', 'drop-me-swarm');

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);          // fan out branches
    (new AdvanceDurableBranch($response->runId, 'parallel:0'))->handle($manager); // FakeResearcher branch

    $entries = frozenEntriesForRun($response->runId);

    expect($entries)->not->toBeEmpty();
    foreach ($entries as $entry) {
        expect($entry['key'])->toBe(RestrictivePropagationPolicy::ALLOWED_KEY);
    }
    $values = array_map(static fn (array $entry): mixed => $entry['value'], $entries);
    expect($values)->toContain('keep-me')        // Run scope, allow-listed
        ->toContain('agent-keep')                // Agent scope gathered AND allow-listed
        ->not->toContain('drop-me-run')
        ->not->toContain('drop-me-agent')        // Agent scope gathered but filtered out
        ->not->toContain('drop-me-swarm');
});
