<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryForgotten;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryRedacted;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryScopeOutOfSnapshot;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWriteSkipped;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\CacheMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\MemoryReplayCoordinator;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Memory\MemoryWriteOutcome;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\ReplaySwarmMemory;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Support\InMemoryMemoryStore;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingMemoryCapturePolicy;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

/**
 * Unit tests for the {@see ReplaySwarmMemory} decorator.
 *
 * Verifies the four-quadrant behaviour:
 *
 * 1. Reads against the replayed Run scope come from the frozen snapshot —
 *    not from whatever the live store currently holds.
 * 2. Writes against the replayed Run scope are buffered before commit and are
 *    visible to subsequent reads in the same invocation (write-after-read
 *    locality). Their ordered mutations can be committed after the step's
 *    success gate.
 * 3. Reads/writes against any other scope read-through to the live store
 *    and dispatch {@see MemoryScopeOutOfSnapshot} so cross-scope drift is
 *    visible in the audit trail.
 * 4. `forget()` on the replayed Run scope clears the buffered value and masks
 *    the snapshot entry before commit; its mutation is committed in order with
 *    writes after the step succeeds.
 */
function makeSnapshot(string $runId, array $entries = []): MemorySnapshot
{
    $hydrated = [];

    foreach ($entries as [$scope, $scopeId, $key, $value, $metadata]) {
        $hydrated[] = new MemoryEntry(
            scope: $scope,
            scopeId: $scopeId,
            key: $key,
            value: $value,
            metadata: $metadata,
        );
    }

    return MemorySnapshot::fromEntries($runId, 0, $hydrated);
}

function makeReplay(MemorySnapshot $snapshot, ?SwarmMemory $live = null): ReplaySwarmMemory
{
    return app()->make(ReplaySwarmMemory::class, [
        'live' => $live ?? new DefaultSwarmMemory(new InMemoryMemoryStore),
        'snapshot' => $snapshot,
        'events' => app(Dispatcher::class),
    ]);
}

beforeEach(function () {
    // The replay decorator dispatches events through the container; reset the
    // fake on every test so cross-test assertions don't leak.
    Event::fake([MemoryForgotten::class, MemoryRedacted::class, MemoryScopeOutOfSnapshot::class, MemoryWriteSkipped::class, MemoryWritten::class]);
});

test('reads against the replayed Run scope return the frozen snapshot value, not the live store', function () {
    $live = new DefaultSwarmMemory(new InMemoryMemoryStore);
    $live->put(MemoryScope::Run, 'run-1', 'config', 'drifted-since-original');

    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'config', 'frozen-at-invocation', []],
    ]);

    $replay = makeReplay($snapshot, $live);

    expect($replay->get(MemoryScope::Run, 'run-1', 'config'))->toBe('frozen-at-invocation');
});

test('entry on the replayed Run scope returns a MemoryEntry hydrated from the snapshot row', function () {
    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'k', 'v', ['source' => 'frozen']],
    ]);

    $entry = makeReplay($snapshot)->entry(MemoryScope::Run, 'run-1', 'k');

    expect($entry)->toBeInstanceOf(MemoryEntry::class);
    expect($entry?->value)->toBe('v');
    expect($entry?->metadata)->toBe(['source' => 'frozen']);
    expect($entry?->scope)->toBe(MemoryScope::Run);
});

test('reads for keys missing from the snapshot return null without touching the live store', function () {
    $live = new DefaultSwarmMemory(new InMemoryMemoryStore);
    $live->put(MemoryScope::Run, 'run-1', 'leaked', 'live-value');

    $snapshot = makeSnapshot('run-1'); // No entries.

    expect(makeReplay($snapshot, $live)->get(MemoryScope::Run, 'run-1', 'leaked'))->toBeNull();
});

test('writes against the replayed Run scope do not reach the live store before commit', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    $snapshot = makeSnapshot('run-1');
    $replay = makeReplay($snapshot, $live);

    $replay->put(MemoryScope::Run, 'run-1', 'scratchpad', 'buffered');

    expect($store->all(MemoryScope::Run, 'run-1'))->toBeEmpty();
});

test('a buffered write is visible to subsequent reads in the same invocation', function () {
    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'snapshot-only', 'original', []],
    ]);
    $replay = makeReplay($snapshot);

    $replay->put(MemoryScope::Run, 'run-1', 'scratchpad', 'just-written');

    expect($replay->get(MemoryScope::Run, 'run-1', 'scratchpad'))->toBe('just-written');
    expect($replay->get(MemoryScope::Run, 'run-1', 'snapshot-only'))->toBe('original');
});

test('a buffered write that overlays a snapshot key returns the buffer on subsequent reads', function () {
    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'k', 'snapshot-value', []],
    ]);
    $replay = makeReplay($snapshot);

    $replay->put(MemoryScope::Run, 'run-1', 'k', 'overlay-value');

    expect($replay->get(MemoryScope::Run, 'run-1', 'k'))->toBe('overlay-value');
});

test('a skipped replay write is marked and leaves an earlier overlay unchanged', function () {
    $policy = new class implements MemoryCapturePolicy
    {
        public CaptureDecision $decision = CaptureDecision::Full;

        public function memory(
            MemoryScope $scope,
            string $key,
            ?RunContext $context = null,
            ?Actor $actor = null,
        ): CaptureDecision {
            return $this->decision;
        }
    };
    app()->instance(MemoryCapturePolicy::class, $policy);
    $replay = makeReplay(makeSnapshot('run-1'));
    $replay->put(MemoryScope::Run, 'run-1', 'secret', 'first');

    $policy->decision = CaptureDecision::Skip;
    $returned = $replay->put(MemoryScope::Run, 'run-1', 'secret', 'replacement');

    expect($replay->get(MemoryScope::Run, 'run-1', 'secret'))->toBe('first')
        ->and(MemoryWriteOutcome::wasSkipped($returned))->toBeTrue();
    Event::assertDispatched(MemoryWriteSkipped::class, fn (MemoryWriteSkipped $event): bool => $event->key === 'secret');
    Event::assertNotDispatched(MemoryWritten::class);
});

test('a skipped replay write does not clear a prior forget mask', function () {
    app()->instance(MemoryCapturePolicy::class, new SkippingMemoryCapturePolicy(['secret']));
    $replay = makeReplay(makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'secret', 'frozen', []],
    ]));
    $replay->forget(MemoryScope::Run, 'run-1', 'secret');

    $returned = $replay->put(MemoryScope::Run, 'run-1', 'secret', 'replacement');

    expect($replay->entry(MemoryScope::Run, 'run-1', 'secret'))->toBeNull()
        ->and(MemoryWriteOutcome::wasSkipped($returned))->toBeTrue();
});

test('forget on a replayed Run-scope key leaves the live store untouched before commit', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    $live->put(MemoryScope::Run, 'run-1', 'k', 'live-value');

    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'k', 'frozen-value', []],
    ]);

    $replay = makeReplay($snapshot, $live);

    expect($replay->forget(MemoryScope::Run, 'run-1', 'k'))->toBeTrue();
    expect($replay->get(MemoryScope::Run, 'run-1', 'k'))->toBeNull();
    expect($store->get(MemoryScope::Run, 'run-1', 'k')?->value)->toBe('live-value');
});

test('replayed puts and forgets are exposed in operation order for commit', function () {
    $replay = makeReplay(makeSnapshot('run-1'));

    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'first', ['source' => 'retry']);
    $replay->forget(MemoryScope::Run, 'run-1', 'finding');
    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'second');

    expect($replay->mutations()->toArray())->toBe([
        ['op' => 'put', 'scope_id' => 'run-1', 'key' => 'finding', 'value' => 'first', 'metadata' => ['source' => 'retry']],
        ['op' => 'forget', 'scope_id' => 'run-1', 'key' => 'finding'],
        ['op' => 'put', 'scope_id' => 'run-1', 'key' => 'finding', 'value' => 'second', 'metadata' => []],
    ]);
});

test('committing replay mutations applies puts and forgets in order and ends with the last write', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = makeReplay(makeSnapshot('run-1'), $live);

    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'first');
    $replay->forget(MemoryScope::Run, 'run-1', 'finding');
    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'second');

    (new MemoryReplayCoordinator(new RecordingSnapshotsMemory, app(), app(Dispatcher::class)))
        ->apply($replay->mutations());

    expect($store->get(MemoryScope::Run, 'run-1', 'finding')?->value)->toBe('second')
        ->and($replay->mutations()->isApplied())->toBeTrue();
});

test('committing replay mutations emits the usual memory events in operation order', function () {
    $events = new Dispatcher(app());
    $order = [];
    $events->listen(MemoryWritten::class, function () use (&$order): void {
        $order[] = MemoryWritten::class;
    });
    $events->listen(MemoryForgotten::class, function () use (&$order): void {
        $order[] = MemoryForgotten::class;
    });
    $live = new DefaultSwarmMemory(new CacheMemoryStore(
        app(CacheFactory::class),
        app(ConfigRepository::class),
        $events,
    ));
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = makeReplay(makeSnapshot('run-event-order'), $live);
    $replay->put(MemoryScope::Run, 'run-event-order', 'finding', 'first');
    $replay->forget(MemoryScope::Run, 'run-event-order', 'finding');
    $replay->put(MemoryScope::Run, 'run-event-order', 'finding', 'second');

    (new MemoryReplayCoordinator(new RecordingSnapshotsMemory, app(), app(Dispatcher::class)))
        ->apply($replay->mutations());

    expect($order)->toBe([
        MemoryWritten::class,
        MemoryForgotten::class,
        MemoryWritten::class,
    ]);
});

test('committing a replay forget removes a live key hidden by the frozen snapshot', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    $live->put(MemoryScope::Run, 'run-1', 'hidden-drift', 'live-value');
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = makeReplay(makeSnapshot('run-1'), $live);

    expect($replay->forget(MemoryScope::Run, 'run-1', 'hidden-drift'))->toBeFalse();

    (new MemoryReplayCoordinator(new RecordingSnapshotsMemory, app(), app(Dispatcher::class)))
        ->apply($replay->mutations());

    expect($store->get(MemoryScope::Run, 'run-1', 'hidden-drift'))->toBeNull();
});

test('a second commit applies nothing', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = makeReplay(makeSnapshot('run-1'), $live);
    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'retry-value');
    $coordinator = new MemoryReplayCoordinator(new RecordingSnapshotsMemory, app(), app(Dispatcher::class));

    $coordinator->apply($replay->mutations());
    $firstUpdatedAt = $store->get(MemoryScope::Run, 'run-1', 'finding')?->updatedAt;
    $this->travel(1)->second();
    $coordinator->apply($replay->mutations());

    expect($store->get(MemoryScope::Run, 'run-1', 'finding')?->updatedAt)->toEqual($firstUpdatedAt);
});

test('commit overwrites a value written by the original attempt', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    $live->put(MemoryScope::Run, 'run-1', 'finding', 'original-attempt');
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = makeReplay(makeSnapshot('run-1'), $live);
    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'retry-value');

    (new MemoryReplayCoordinator(new RecordingSnapshotsMemory, app(), app(Dispatcher::class)))
        ->apply($replay->mutations());

    expect($store->get(MemoryScope::Run, 'run-1', 'finding')?->value)->toBe('retry-value');
});

test('commit leaves the frozen snapshot entries unchanged', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    app()->instance(DefaultSwarmMemory::class, $live);
    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'finding', 'frozen-value', ['source' => 'snapshot']],
    ]);
    $entries = $snapshot->entries;
    $replay = makeReplay($snapshot, $live);
    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'retry-value');

    (new MemoryReplayCoordinator(new RecordingSnapshotsMemory, app(), app(Dispatcher::class)))
        ->apply($replay->mutations());

    expect($snapshot->entries)->toBe($entries);
});

test('commit applies Redact through the live store policy', function () {
    $policy = new class implements MemoryCapturePolicy
    {
        public CaptureDecision $decision = CaptureDecision::Full;

        public function memory(MemoryScope $scope, string $key, ?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return $this->decision;
        }
    };
    app()->instance(MemoryCapturePolicy::class, $policy);
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory(new RedactingMemoryStore($store, $policy, app(Dispatcher::class)));
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = makeReplay(makeSnapshot('run-1'), $live);
    $replay->put(MemoryScope::Run, 'run-1', 'secret', ['token' => 'raw']);

    $policy->decision = CaptureDecision::Redact;
    (new MemoryReplayCoordinator(new RecordingSnapshotsMemory, app(), app(Dispatcher::class)))
        ->apply($replay->mutations());

    expect($store->get(MemoryScope::Run, 'run-1', 'secret')?->value)->toBe(['token' => '[redacted]']);
    Event::assertDispatched(MemoryRedacted::class, fn (MemoryRedacted $event): bool => $event->key === 'secret');
});

test('a put-time Skip is never logged for commit', function () {
    app()->instance(MemoryCapturePolicy::class, new SkippingMemoryCapturePolicy(['secret']));
    $replay = makeReplay(makeSnapshot('run-1'));

    $entry = $replay->put(MemoryScope::Run, 'run-1', 'secret', 'raw');

    expect(MemoryWriteOutcome::wasSkipped($entry))->toBeTrue()
        ->and($replay->mutations()->isEmpty())->toBeTrue();
});

test('a commit-time Skip fails the commit and leaves the log unapplied', function () {
    $policy = new class implements MemoryCapturePolicy
    {
        public CaptureDecision $decision = CaptureDecision::Full;

        public function memory(MemoryScope $scope, string $key, ?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return $this->decision;
        }
    };
    app()->instance(MemoryCapturePolicy::class, $policy);
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory(new RedactingMemoryStore($store, $policy, app(Dispatcher::class)));
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = makeReplay(makeSnapshot('run-1'), $live);
    $replay->put(MemoryScope::Run, 'run-1', 'secret', 'raw');
    $policy->decision = CaptureDecision::Skip;
    $coordinator = new MemoryReplayCoordinator(new RecordingSnapshotsMemory, app(), app(Dispatcher::class));

    expect(fn () => $coordinator->apply($replay->mutations()))
        ->toThrow(SwarmException::class, 'Replay memory commit skipped an accepted write [secret].');

    expect($store->get(MemoryScope::Run, 'run-1', 'secret'))->toBeNull()
        ->and($replay->mutations()->isApplied())->toBeFalse();
    Event::assertDispatched(MemoryWriteSkipped::class, fn (MemoryWriteSkipped $event): bool => $event->key === 'secret');
});

test('forget returns false when neither the snapshot nor the buffer holds the key', function () {
    $snapshot = makeSnapshot('run-1');

    expect(makeReplay($snapshot)->forget(MemoryScope::Run, 'run-1', 'missing'))->toBeFalse();
});

test('all on the replayed Run scope merges snapshot entries with buffered writes and excludes forgets', function () {
    $snapshot = makeSnapshot('run-1', [
        [MemoryScope::Run, 'run-1', 'a', '1', []],
        [MemoryScope::Run, 'run-1', 'b', '2', []],
        [MemoryScope::Run, 'run-1', 'c', '3', []],
    ]);

    $replay = makeReplay($snapshot);
    $replay->put(MemoryScope::Run, 'run-1', 'b', 'overlay-b'); // overlay
    $replay->put(MemoryScope::Run, 'run-1', 'd', '4');          // new
    $replay->forget(MemoryScope::Run, 'run-1', 'c');            // mask

    $values = collect($replay->all(MemoryScope::Run, 'run-1'))
        ->mapWithKeys(fn (MemoryEntry $entry) => [$entry->key => $entry->value])
        ->all();

    expect($values)->toBe([
        'a' => '1',
        'b' => 'overlay-b',
        'd' => '4',
    ]);
});

test('reads against non-Run scopes read-through to the live store and dispatch MemoryScopeOutOfSnapshot', function () {
    $live = new DefaultSwarmMemory(new InMemoryMemoryStore);
    $live->put(MemoryScope::Conversation, 'conv-1', 'tone', 'casual');

    $snapshot = makeSnapshot('run-1');

    expect(makeReplay($snapshot, $live)->get(MemoryScope::Conversation, 'conv-1', 'tone'))->toBe('casual');

    Event::assertDispatched(MemoryScopeOutOfSnapshot::class, fn (MemoryScopeOutOfSnapshot $e): bool => $e->runId === 'run-1'
        && $e->scope === MemoryScope::Conversation
        && $e->scopeId === 'conv-1'
        && $e->key === 'tone'
        && $e->operation === 'entry');
});

test('writes against non-Run scopes hit the live store and dispatch MemoryScopeOutOfSnapshot with operation=put', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    $snapshot = makeSnapshot('run-1');

    makeReplay($snapshot, $live)->put(MemoryScope::Agent, 'App\\Agents\\Tagger', 'preference', 'tagged');

    expect($store->get(MemoryScope::Agent, 'App\\Agents\\Tagger', 'preference')?->value)->toBe('tagged');

    Event::assertDispatched(MemoryScopeOutOfSnapshot::class, fn (MemoryScopeOutOfSnapshot $e): bool => $e->scope === MemoryScope::Agent
        && $e->operation === 'put');
});

test('reads against the Run scope of a DIFFERENT run dispatch the cross-scope event and read live', function () {
    $live = new DefaultSwarmMemory(new InMemoryMemoryStore);
    $live->put(MemoryScope::Run, 'run-other', 'k', 'other-run-value');

    $snapshot = makeSnapshot('run-1');

    expect(makeReplay($snapshot, $live)->get(MemoryScope::Run, 'run-other', 'k'))->toBe('other-run-value');

    Event::assertDispatched(MemoryScopeOutOfSnapshot::class, fn (MemoryScopeOutOfSnapshot $e): bool => $e->scopeId === 'run-other');
});

test('all against a non-Run scope dispatches MemoryScopeOutOfSnapshot with a wildcard key', function () {
    $live = new DefaultSwarmMemory(new InMemoryMemoryStore);
    $snapshot = makeSnapshot('run-1');

    makeReplay($snapshot, $live)->all(MemoryScope::Swarm, 'App\\Swarms\\MarkasSwarm');

    Event::assertDispatched(MemoryScopeOutOfSnapshot::class, fn (MemoryScopeOutOfSnapshot $e): bool => $e->scope === MemoryScope::Swarm
        && $e->key === '*'
        && $e->operation === 'all');
});

test('snapshot frozen flag round-trips through fromPersisted to true by default', function () {
    $snapshot = MemorySnapshot::fromPersisted(
        ['run_id' => 'r', 'step_index' => 0, 'entries' => []],
        [],
    );

    expect($snapshot->frozen)->toBeTrue();
});

test('snapshot frozen flag round-trips through fromEntries to false by default', function () {
    expect(MemorySnapshot::fromEntries('r', 0, [])->frozen)->toBeFalse();
});

test('withClearedToolCalls returns an unfrozen snapshot with empty toolCalls', function () {
    $snapshot = MemorySnapshot::fromPersisted(
        ['run_id' => 'r', 'step_index' => 0, 'entries' => []],
        [['name' => 't', 'arguments' => [], 'result' => 'r']],
    );

    expect($snapshot->frozen)->toBeTrue();
    expect($snapshot->toolCalls)->toHaveCount(1);

    $cleared = $snapshot->withClearedToolCalls();

    expect($cleared->toolCalls)->toBe([]);
    expect($cleared->frozen)->toBeFalse();
});
