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
