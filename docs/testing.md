# Testing

Laravel Swarm includes two complementary testing styles:

- faking swarm execution
- asserting against real persisted or dispatched runtime behavior

Most tests should start with fakes.

## Faking A Swarm

Use `fake()` to intercept execution:

```php
use App\Ai\Swarms\ArticlePipeline;

ArticlePipeline::fake(['first', 'second']);

expect((string) ArticlePipeline::make()->prompt('Draft a blog outline about Laravel queues.'))->toBe('first');
```

## Asserting Basic Interaction

You can assert against synchronous, queued, durable, and streamed execution:

```php
ArticlePipeline::assertPrompted('Draft a blog outline about Laravel queues.');
ArticlePipeline::assertQueued('Draft a blog outline about Laravel queues.');
ArticlePipeline::assertStreamed('Draft a blog outline about Laravel queues.');
ArticlePipeline::assertDispatchedDurably('Draft a blog outline about Laravel queues.');
```

Durable operator features should be tested through the manager or response
helpers:

```php
$response = ApprovalSwarm::make()->dispatchDurable('review document');

app(DurableSwarmManager::class)->wait($response->runId, 'approval_received');

$result = $response->signal('approval_received', ['approved' => true], 'approval-1');

expect($result->accepted)->toBeTrue();
expect($response->inspect()->waits[0]['status'])->toBe('signalled');
```

## Database-backed durable execution

`SwarmFake` records dispatch intent for `dispatchDurable()`; it does **not**
execute `DurableSwarmManager`, simulate hierarchical coordination rows, or run
durable jobs. When you need to prove leases, checkpoints, retries, branch joins,
or job redispatch behavior, use **feature-style tests** with database
persistence and migrations loaded (see the package `TestCase` and
`tests/Feature/DurableSwarmTest.php`).

Common patterns:

- Bind a test double for `BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher`
  **before** resolving `DurableSwarmManager` so you can assert how many
  `AdvanceDurableSwarm` / `AdvanceDurableBranch` dispatches occurred or inject
  controlled failures.
- Bind `BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder` if you need to spy
  checkpoint writes; the service provider documents `makeWith` parameters.

For the full collaborator graph, container singleton rules, and which types
must not be registered globally, read
[Durable Runtime Architecture](durable-runtime-architecture.md).

Webhook tests should sign the exact raw request body with
`hash_hmac('sha256', $timestamp.'.'.$body, $secret)` and assert unsigned
requests are rejected.

Streaming behavior and event types are documented in [Streaming](streaming.md).

Faked streams are lazy, so `assertStreamed()` records after the stream response
is iterated, returned from a controller response, or consumed by `broadcast()` /
`broadcastNow()`. `broadcastOnQueue()` records in the queued bucket, so use
`assertQueued()` for queued stream-broadcast jobs. There is intentionally no
separate broadcast assertion family.

## Asserting A Swarm Was Not Prompted

```php
ArticlePipeline::assertNeverPrompted();
ArticlePipeline::assertNeverQueued();
ArticlePipeline::assertNeverStreamed();
ArticlePipeline::assertNeverDispatchedDurably();
```

## Asserting Structured Input

Array assertions use subset matching, so you only need to assert on the keys
you care about:

```php
ArticlePipeline::make()->prompt([
    'draft_id' => 42,
    'mode' => 'outline',
    'topic' => 'Laravel queues',
]);

ArticlePipeline::assertPrompted(['draft_id' => 42]);
```

The same pattern works for `assertQueued()`, `assertStreamed()`, and
`assertDispatchedDurably()`.

## Using Callable Assertions

Use a callable when you need more control over the recorded task value:

```php
ArticlePipeline::assertPrompted(function ($task) {
    return is_array($task)
        && ($task['topic'] ?? null) === 'Laravel queues';
});
```

When the swarm was called with a string, the callback receives a string. When
it was called with an array or `RunContext`, the callback receives the original
structured value.

## Asserting Persisted Runs

When you want to verify real execution rather than fake interaction, use
`assertPersisted()`:

```php
$response = ArticlePipeline::make()->prompt([
    'draft_id' => 42,
    'topic' => 'Laravel queues',
]);

ArticlePipeline::assertPersisted($response->metadata['run_id'], 'completed');
ArticlePipeline::assertPersisted(['draft_id' => 42]);
```

Array assertions on `assertPersisted()` match against the persisted task/context
shape only:

- `input`
- `data`
- `metadata`

## Asserting Lifecycle Events

Use `assertEventFired()` when you want to verify that a swarm lifecycle event
was recorded during a test.

To activate the recorder in your test case, use the package's
`InteractsWithSwarmEvents` trait:

```php
use BuiltByBerry\LaravelSwarm\Testing\InteractsWithSwarmEvents;

class ArticlePipelineTest extends TestCase
{
    use InteractsWithSwarmEvents;
}
```

The recorder resets automatically between tests.

Once the trait is in place, assert lifecycle events after a real prompt:

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;

ArticlePipeline::make()->prompt('Draft a blog outline about Laravel queues.');

ArticlePipeline::assertEventFired(SwarmStarted::class);
```

You may also pass a callback to inspect the event payload:

```php
ArticlePipeline::assertEventFired(
    SwarmStarted::class,
    fn ($event) => $event->executionMode === 'run',
);
```

Synchronous `prompt()` calls use the existing `run` execution mode value for
compatibility.

`assertEventFired()` is test-scoped and will fail with a clear message if the
recorder has not been activated.

## Choosing Between The Two Styles

Use fakes when you want to test how your application interacts with a swarm.

Use persisted and lifecycle assertions when you want to verify what a real
swarm execution produced.
