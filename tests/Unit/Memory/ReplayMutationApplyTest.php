<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryForgotten;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryRedacted;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWriteSkipped;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\CacheMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\MemoryReplayCoordinator;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\ReplayMutationLog;
use BuiltByBerry\LaravelSwarm\Memory\ReplaySwarmMemory;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Support\InMemoryMemoryStore;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

function applyTestSnapshot(string $runId, array $entries = []): MemorySnapshot
{
    $hydrated = [];

    foreach ($entries as [$scope, $scopeId, $key, $value, $metadata]) {
        $hydrated[] = new MemoryEntry($scope, $scopeId, $key, $value, $metadata);
    }

    return MemorySnapshot::fromEntries($runId, 0, $hydrated);
}

function applyTestReplay(MemorySnapshot $snapshot, SwarmMemory $live): ReplaySwarmMemory
{
    return app()->make(ReplaySwarmMemory::class, [
        'live' => $live,
        'snapshot' => $snapshot,
        'events' => app(Dispatcher::class),
    ]);
}

function applyTestCoordinator(): MemoryReplayCoordinator
{
    return new MemoryReplayCoordinator(new RecordingSnapshotsMemory, app(), app(Dispatcher::class));
}

beforeEach(function () {
    Event::fake([MemoryForgotten::class, MemoryRedacted::class, MemoryWriteSkipped::class, MemoryWritten::class]);
});

test('applying replay mutations preserves puts and forgets in order', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = applyTestReplay(applyTestSnapshot('run-1'), $live);
    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'first');
    $replay->forget(MemoryScope::Run, 'run-1', 'finding');
    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'second');

    applyTestCoordinator()->apply($replay->mutations(), 'run-1');

    expect($store->get(MemoryScope::Run, 'run-1', 'finding')?->value)->toBe('second')
        ->and($replay->mutations()->isApplied())->toBeTrue();
});

test('applying an empty log does not resolve the live memory store', function () {
    app()->bind(DefaultSwarmMemory::class, fn () => throw new RuntimeException('live-store-resolved'));

    applyTestCoordinator()->apply(new ReplayMutationLog, 'run-1');

    expect(true)->toBeTrue();
});

test('applying process replay mutations rejects a foreign run scope', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = applyTestReplay(applyTestSnapshot('foreign-run'), $live);
    $replay->put(MemoryScope::Run, 'foreign-run', 'finding', 'foreign-value');

    expect(fn () => applyTestCoordinator()->apply($replay->mutations(), 'expected-run'))
        ->toThrow(SwarmException::class, 'foreign run scope');

    expect($store->get(MemoryScope::Run, 'foreign-run', 'finding'))->toBeNull();
});

test('applying replay mutations emits the usual memory events in operation order', function () {
    $events = new Dispatcher(app());
    $order = [];
    $events->listen(MemoryWritten::class, function () use (&$order): void {
        $order[] = MemoryWritten::class;
    });
    $events->listen(MemoryForgotten::class, function () use (&$order): void {
        $order[] = MemoryForgotten::class;
    });
    $live = new DefaultSwarmMemory(new CacheMemoryStore(app(CacheFactory::class), app(ConfigRepository::class), $events));
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = applyTestReplay(applyTestSnapshot('run-event-order'), $live);
    $replay->put(MemoryScope::Run, 'run-event-order', 'finding', 'first');
    $replay->forget(MemoryScope::Run, 'run-event-order', 'finding');
    $replay->put(MemoryScope::Run, 'run-event-order', 'finding', 'second');

    applyTestCoordinator()->apply($replay->mutations(), 'run-event-order');

    expect($order)->toBe([MemoryWritten::class, MemoryForgotten::class, MemoryWritten::class]);
});

test('applying a replay forget removes a live key hidden by the frozen snapshot', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    $live->put(MemoryScope::Run, 'run-1', 'hidden-drift', 'live-value');
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = applyTestReplay(applyTestSnapshot('run-1'), $live);
    expect($replay->forget(MemoryScope::Run, 'run-1', 'hidden-drift'))->toBeFalse();

    applyTestCoordinator()->apply($replay->mutations(), 'run-1');

    expect($store->get(MemoryScope::Run, 'run-1', 'hidden-drift'))->toBeNull();
});

test('an applied replay mutation log is not applied twice', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = applyTestReplay(applyTestSnapshot('run-1'), $live);
    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'retry-value');
    $coordinator = applyTestCoordinator();
    $coordinator->apply($replay->mutations(), 'run-1');
    $firstUpdatedAt = $store->get(MemoryScope::Run, 'run-1', 'finding')?->updatedAt;
    $this->travel(1)->second();

    $coordinator->apply($replay->mutations(), 'run-1');

    expect($store->get(MemoryScope::Run, 'run-1', 'finding')?->updatedAt)->toEqual($firstUpdatedAt);
});

test('applying replay mutations overwrites a value from the original attempt', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    $live->put(MemoryScope::Run, 'run-1', 'finding', 'original-attempt');
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = applyTestReplay(applyTestSnapshot('run-1'), $live);
    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'retry-value');

    applyTestCoordinator()->apply($replay->mutations(), 'run-1');

    expect($store->get(MemoryScope::Run, 'run-1', 'finding')?->value)->toBe('retry-value');
});

test('applying replay mutations leaves the frozen snapshot unchanged', function () {
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory($store);
    app()->instance(DefaultSwarmMemory::class, $live);
    $snapshot = applyTestSnapshot('run-1', [[MemoryScope::Run, 'run-1', 'finding', 'frozen-value', ['source' => 'snapshot']]]);
    $entries = $snapshot->entries;
    $replay = applyTestReplay($snapshot, $live);
    $replay->put(MemoryScope::Run, 'run-1', 'finding', 'retry-value');

    applyTestCoordinator()->apply($replay->mutations(), 'run-1');

    expect($snapshot->entries)->toBe($entries);
});

test('applying replay mutations applies Redact through the live store policy', function () {
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
    $replay = applyTestReplay(applyTestSnapshot('run-1'), $live);
    $replay->put(MemoryScope::Run, 'run-1', 'secret', ['token' => 'raw']);
    $policy->decision = CaptureDecision::Redact;

    applyTestCoordinator()->apply($replay->mutations(), 'run-1');

    expect($store->get(MemoryScope::Run, 'run-1', 'secret')?->value)->toBe(['token' => '[redacted]']);
    Event::assertDispatched(MemoryRedacted::class, fn (MemoryRedacted $event): bool => $event->key === 'secret');
});

test('a save-time Skip fails with actionable context and leaves the log unapplied', function () {
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
    $replay = applyTestReplay(applyTestSnapshot('run-1'), $live);
    $replay->put(MemoryScope::Run, 'run-1', 'secret', 'raw');
    $policy->decision = CaptureDecision::Skip;

    expect(fn () => applyTestCoordinator()->apply($replay->mutations(), 'run-1'))
        ->toThrow(SwarmException::class, 'Memory capture policy skipped, at save time, write [secret] for run [run-1] after accepting it during the retry. The retried step was already recorded as completed.');

    expect($store->get(MemoryScope::Run, 'run-1', 'secret'))->toBeNull()
        ->and($replay->mutations()->isApplied())->toBeFalse();
    Event::assertDispatched(MemoryWriteSkipped::class, fn (MemoryWriteSkipped $event): bool => $event->key === 'secret');
});

test('a partial save remains stored and a second apply replays the complete unapplied log', function () {
    $policy = new class implements MemoryCapturePolicy
    {
        public int $calls = 0;

        public bool $allowAll = false;

        public function memory(MemoryScope $scope, string $key, ?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            if ($this->allowAll) {
                return CaptureDecision::Full;
            }

            return ++$this->calls === 4 ? CaptureDecision::Skip : CaptureDecision::Full;
        }
    };
    app()->instance(MemoryCapturePolicy::class, $policy);
    $store = new InMemoryMemoryStore;
    $live = new DefaultSwarmMemory(new RedactingMemoryStore($store, $policy, app(Dispatcher::class)));
    app()->instance(DefaultSwarmMemory::class, $live);
    $replay = applyTestReplay(applyTestSnapshot('run-1'), $live);
    $replay->put(MemoryScope::Run, 'run-1', 'first', 'one');
    $replay->put(MemoryScope::Run, 'run-1', 'second', 'two');
    $coordinator = applyTestCoordinator();

    expect(fn () => $coordinator->apply($replay->mutations(), 'run-1'))->toThrow(SwarmException::class);
    $firstUpdatedAt = $store->get(MemoryScope::Run, 'run-1', 'first')?->updatedAt;
    expect($store->get(MemoryScope::Run, 'run-1', 'first')?->value)->toBe('one')
        ->and($store->get(MemoryScope::Run, 'run-1', 'second'))->toBeNull()
        ->and($replay->mutations()->isApplied())->toBeFalse();

    $this->travel(1)->second();
    $policy->allowAll = true;
    $coordinator->apply($replay->mutations(), 'run-1');

    expect($store->get(MemoryScope::Run, 'run-1', 'first')?->updatedAt)->not->toEqual($firstUpdatedAt)
        ->and($store->get(MemoryScope::Run, 'run-1', 'second')?->value)->toBe('two')
        ->and($replay->mutations()->isApplied())->toBeTrue();
});
