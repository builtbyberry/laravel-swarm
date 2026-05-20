# Testing

Laravel Swarm includes two complementary testing styles:

- faking swarm execution
- asserting against real persisted or dispatched runtime behavior

Most tests should start with fakes.

**Guardrails and fakes:** `SwarmFake` records dispatch intent and bypasses the runner entirely. Guardrails do not fire when a swarm is faked. Test guardrail classes directly as plain PHP units using `RunContext::from()` and `GuardrailStepContext`; see [Guardrails Policy](../examples/guardrails-policy/README.md) for examples.

## Faking A Swarm

Use `fake()` to intercept execution:

```php
use App\Ai\Swarms\ArticlePipeline;

ArticlePipeline::fake(['first', 'second']);

expect((string) ArticlePipeline::make()->prompt('Draft a blog outline about Laravel queues.'))->toBe('first');
```

## Building A `RunContext` For Tests

`RunContext::fake()` is a named constructor for ad-hoc test setup. It returns a
context with a deterministic run id (`fake-run-id`), empty input, no actor, no
data, no metadata, and no artifacts. Pass an array of overrides for the slots
you want to populate, and compose with the existing fluent builders
(`withActor()`, `mergeData()`, `mergeMetadata()`, `withLabels()`,
`withDetails()`, `addArtifact()`) for everything else:

```php
use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$context = RunContext::fake([
    'input' => 'Draft a blog outline about Laravel queues.',
    'actor' => 'user:42',
])
    ->mergeData(['draft_id' => 7])
    ->withLabels(['tenant' => 'acme']);

ArticlePipeline::fake();
ArticlePipeline::make()->run($context);

ArticlePipeline::assertDispatchedWithActor(new Actor(id: '42', type: 'user'));
ArticlePipeline::assertPrompted(['draft_id' => 7]);
```

The `actor` override accepts anything `RunContext::withActor()` accepts (an
`Actor`, an `Authenticatable`, a `"type:id"` or bare-id string, or `null`).
Pass it through `RunContext::fake()` when actor binding is the only thing you
need, and reach for `withActor()` directly when chaining with other builders.

`RunContext::fake()` is a test helper. Production code should construct
contexts with `RunContext::from()`, `::fromTask()`, or `::fromPayload()`.

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

## Asserting Actor Binding

`SwarmFake` can assert that a dispatch carried an `Actor` bound via
`RunContext::withActor()`. Bare-string tasks and structured-array tasks
carry no actor — the assertion only sees actors on `RunContext` instances.

```php
use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

ArticlePipeline::fake();

$context = RunContext::fromTask('draft the post')
    ->withActor(new Actor(id: 'u-42', type: 'user'));

ArticlePipeline::make()->run($context);

ArticlePipeline::assertDispatchedWithActor(new Actor(id: 'u-42', type: 'user'));
ArticlePipeline::assertDispatchedWithActor('user:u-42');
ArticlePipeline::assertDispatchedWithActor(
    fn (Actor $actor): bool => $actor->type === 'user',
);
```

Use `assertDispatchedWithAnyActor()` when you only need to confirm that an
actor was bound, and `assertNeverDispatchedWithActor()` to assert the
opposite. All three helpers inspect every dispatch bucket (run, queue,
durable, stream).

If your application code uses `Context::add('swarm:actor', $actor)` instead
of an explicit `withActor()`, pass an explicit `RunContext` to the swarm
dispatch under test so the actor is visible to `SwarmFake`:

```php
Context::add('swarm:actor', $user);

ArticlePipeline::make()->run(
    RunContext::fromTask($task)->withActor($user),
);
```

The `Context` facade binding still flows to the real runner via
`DefaultActorResolver`; the explicit `withActor()` call is just the
SwarmFake-visible mirror.

## Testing Audit Extension Points

The four v0.4 audit extension contracts — `CapturePolicy`,
`SinkFailureHandler`, `SwarmAuditSigner`, and `ActorResolver` — are
invoked inside `SwarmRunner` and the audit dispatcher, code paths that
`SwarmFake` intentionally skips. Three testing patterns cover them:

### Unit-test the contract directly

Custom implementations are plain PHP classes. Construct one with the
inputs you want to exercise and assert on the decision or output:

```php
use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

test('redacts outputs for unauthenticated runs', function () {
    $policy = new MyCapturePolicy;
    $context = RunContext::fromTask('task');

    expect($policy->outputs($context, actor: null))
        ->toBe(CaptureDecision::Redact);

    expect($policy->outputs($context, actor: new Actor(id: 'u-1', type: 'user')))
        ->toBe(CaptureDecision::Full);
});
```

This pattern works for all four contracts. Worked examples live in
`tests/Unit/Audit/` (`CapturePolicyTest`, `SinkFailureHandlerTest`,
`SwarmAuditSignerTest`, `DefaultActorResolverTest`).

### Bind a recording sink for end-to-end audit checks

When you need to verify what an actual run emitted — including the
signed-and-actor-bound envelope — bind a recording sink in your test
setup and inspect the emitted payloads:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;

$sink = new class implements SwarmAuditSink {
    /** @var array<int, array{category: string, payload: array<string, mixed>}> */
    public array $records = [];

    public function emit(string $category, array $payload): void
    {
        $this->records[] = compact('category', 'payload');
    }
};

app()->instance(SwarmAuditSink::class, $sink);

ArticlePipeline::make()->run(
    RunContext::fromTask('task')->withActor('user:42'),
);

expect($sink->records[0]['payload']['metadata']['actor']['id'])->toBe('42');
```

The package ships an internal `RecordingSwarmAuditSink` fixture used by
its own feature tests; copy that shape into your application test
suite if you want a richer recording sink.

### Use `'halt'` failure policy to assert run-level halt behavior

When you want to verify that a signing or sink failure halts the run,
bind a sink that throws, set `swarm.audit.failure_policy=halt`, and
catch the `AuditSinkHaltedException`:

```php
use BuiltByBerry\LaravelSwarm\Exceptions\AuditSinkHaltedException;

config()->set('swarm.audit.failure_policy', 'halt');
app()->instance(SwarmAuditSink::class, new class implements SwarmAuditSink {
    public function emit(string $category, array $payload): void
    {
        throw new RuntimeException('sink down');
    }
});

expect(fn () => ArticlePipeline::make()->run('task'))
    ->toThrow(AuditSinkHaltedException::class);
```

See [Audit Evidence Contract](audit-evidence-contract.md) for the full
extension-point reference.

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
