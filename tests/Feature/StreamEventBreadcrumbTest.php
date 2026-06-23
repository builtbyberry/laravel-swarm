<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Runners\SequentialStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming\UnknownStreamEvent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRichStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalSingleRichWorkerSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalUnknownStreamEventSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeUnknownStreamEventSwarm;
use Illuminate\Support\Facades\Artisan;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Collects every log record so the breadcrumb can be asserted on without
 * touching the real log stack. AbstractLogger routes ->warning() through log().
 *
 * @phpstan-type LogRecord array{level: mixed, message: string|Stringable, context: array<string, mixed>}
 */
class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /** @return list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> */
    public function unknownEventBreadcrumbs(): array
    {
        return array_values(array_filter(
            $this->records,
            fn (array $record): bool => str_contains((string) $record['message'], 'unrecognized stream event type'),
        ));
    }
}

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    // The multi-agent handled-stream fixture (FakeRichStreamingSwarm) leads with
    // these fakes; prime them so no agent attempts a real provider call.
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);

    // Bind the collector under LoggerInterface only. Container::instance() drops
    // the Psr\Log\LoggerInterface -> 'log' alias, so the runners (which inject
    // LoggerInterface) receive the collector while the rest of the framework
    // keeps the real log manager under 'log'.
    $this->logger = new CollectingLogger;
    app()->instance(LoggerInterface::class, $this->logger);
});

// ---------------------------------------------------------------------------
// Breadcrumb fires — per runner
// ---------------------------------------------------------------------------

test('SequentialRunner breadcrumbs an unrecognized stream event', function () {
    iterator_to_array(FakeUnknownStreamEventSwarm::make()->stream('stream-task'));

    $breadcrumbs = $this->logger->unknownEventBreadcrumbs();

    expect($breadcrumbs)->toHaveCount(1);
    expect($breadcrumbs[0]['context']['event_classes'])->toBe([UnknownStreamEvent::class]);
    expect($breadcrumbs[0]['context'])->toHaveKey('run_id');
    expect($breadcrumbs[0]['context']['step_index'])->toBe(0);
});

test('StaticHierarchicalStreamRunner breadcrumbs an unrecognized stream event', function () {
    iterator_to_array(FakeStaticHierarchicalUnknownStreamEventSwarm::make()->stream('stream-task'));

    $breadcrumbs = $this->logger->unknownEventBreadcrumbs();

    expect($breadcrumbs)->toHaveCount(1);
    expect($breadcrumbs[0]['context']['event_classes'])->toBe([UnknownStreamEvent::class]);
    expect($breadcrumbs[0]['context'])->toHaveKey('run_id');
    expect($breadcrumbs[0]['context']['step_index'])->toBe(0);
});

// ---------------------------------------------------------------------------
// Degrade-safe: log, never throw; class-only, never payload
// ---------------------------------------------------------------------------

test('an unrecognized event does not abort the run', function () {
    // The run completes and still yields the handled events — the unknown
    // event between them must not throw or short-circuit the stream.
    $events = iterator_to_array(FakeUnknownStreamEventSwarm::make()->stream('stream-task'));
    $types = array_map(fn ($event): string => $event->type(), $events);

    expect($types)->toContain('swarm_text_delta');
    expect($types)->toContain('swarm_stream_end');
});

test('the breadcrumb records the event class only, never its payload', function () {
    iterator_to_array(FakeUnknownStreamEventSwarm::make()->stream('stream-task'));

    $breadcrumbs = $this->logger->unknownEventBreadcrumbs();
    expect($breadcrumbs)->toHaveCount(1);

    // The unknown event carries a content-bearing field; the breadcrumb must
    // never serialize the body — only the class name (a type identifier).
    $serialized = json_encode([$breadcrumbs[0]['message'], $breadcrumbs[0]['context']]);
    expect($serialized)->not->toContain('super-secret-unredacted-payload');
});

// ---------------------------------------------------------------------------
// No breadcrumb on fully-handled streams
// ---------------------------------------------------------------------------

test('SequentialRunner does not breadcrumb a fully-handled stream', function () {
    iterator_to_array(FakeRichStreamingSwarm::make()->stream('stream-task'));

    expect($this->logger->unknownEventBreadcrumbs())->toHaveCount(0);
});

test('StaticHierarchicalStreamRunner does not breadcrumb a fully-handled stream', function () {
    iterator_to_array(FakeStaticHierarchicalSingleRichWorkerSwarm::make()->stream('stream-task'));

    expect($this->logger->unknownEventBreadcrumbs())->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Chain parity (drift guard)
// ---------------------------------------------------------------------------

test('both runners agree on handled vs unrecognized events', function () {
    // A fully-handled stream breadcrumbs in neither runner; an unrecognized
    // event breadcrumbs in both. Asserted behaviorally so the guard survives a
    // refactor of either instanceof chain — a hardcoded class-name list would
    // drift in lockstep with the code it is meant to pin.

    $count = function (callable $run): int {
        $logger = new CollectingLogger;
        app()->instance(LoggerInterface::class, $logger);

        // The runners are singletons that capture the logger at construction.
        // Forget the chain (SwarmRunner injects all three) so the next stream()
        // rebuilds them against the logger just bound.
        foreach ([SwarmRunner::class, SequentialStreamRunner::class, StaticHierarchicalStreamRunner::class, SequentialRunner::class] as $abstract) {
            app()->forgetInstance($abstract);
        }

        $run();

        return count($logger->unknownEventBreadcrumbs());
    };

    $sequentialHandled = $count(fn () => iterator_to_array(FakeRichStreamingSwarm::make()->stream('stream-task')));
    $hierarchicalHandled = $count(fn () => iterator_to_array(FakeStaticHierarchicalSingleRichWorkerSwarm::make()->stream('stream-task')));
    $sequentialUnknown = $count(fn () => iterator_to_array(FakeUnknownStreamEventSwarm::make()->stream('stream-task')));
    $hierarchicalUnknown = $count(fn () => iterator_to_array(FakeStaticHierarchicalUnknownStreamEventSwarm::make()->stream('stream-task')));

    expect($sequentialHandled)->toBe($hierarchicalHandled)->toBe(0);
    expect($sequentialUnknown)->toBe($hierarchicalUnknown)->toBe(1);
});
