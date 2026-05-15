# RunContext

`RunContext` is the envelope that carries the original task, run identity, and structured carry-forward data throughout a swarm run. It is created when a swarm is invoked — either automatically from the string or array you pass to `prompt()`, or explicitly when you construct one yourself — and it is available on the `SwarmResponse` returned after the run completes. Every persistence store, lifecycle event, and durable checkpoint operates on the same `RunContext` instance, so anything you put into it at dispatch time is accessible for the lifetime of the run.

## Construction Patterns

All swarm execution methods — `prompt()`, `run()`, `queue()`, `stream()`, `broadcast()`, `broadcastNow()`, `broadcastOnQueue()`, and `dispatchDurable()` — accept the same three input shapes.

### From a string

The simplest form. Pass a single prompt string and let the swarm build the context automatically:

```php
$response = BlogPostSwarm::make()->prompt('Write a blog post about Laravel queues for intermediate developers.');
```

### From an array

Use an array when the task has distinct named parts. The array is serialized as the prompt each agent receives and is also stored as structured data in the run context:

```php
$response = BlogPostSwarm::make()->prompt([
    'topic'    => 'Laravel queues',
    'audience' => 'intermediate developers',
    'tone'     => 'technical',
]);
```

Array input must be plain data: strings, integers, floats, booleans, null, and nested arrays of those types. Objects, enums, closures, and other non-serializable values are rejected.

### Explicit RunContext

Use `RunContext` directly when you need to set a run ID yourself, attach metadata before dispatch, or pre-load artifacts:

```php
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$context = RunContext::from('Write a blog post about Laravel queues.')
    ->withLabels(['source' => 'webhook', 'tenant_id' => 'acme'])
    ->withDetails(['request_id' => $request->id()]);

$response = BlogPostSwarm::make()->prompt($context);
```

#### `RunContext::from()`

The primary factory. Accepts a string, a structured context payload array, or an existing `RunContext` instance (which is returned as-is).

```php
// From a string
$context = RunContext::from('Write a blog post about Laravel queues.');

// From a string with an explicit run ID for idempotency
$context = RunContext::from('Write a blog post about Laravel queues.', runId: 'my-idempotency-key');

// From an explicit context payload array
// The array must contain an [input] string key; [data], [metadata], and [artifacts] are optional
$context = RunContext::from([
    'input'    => 'Write a blog post about Laravel queues.',
    'data'     => ['topic' => 'Laravel queues'],
    'metadata' => ['trace_id' => 'abc-123'],
]);
```

When you pass `RunContext::from()` an array, the array is a **context payload**, not a task payload. The `input` key is required and must be a string. To pass a structured task array as the prompt, use `RunContext::fromTask()` instead, or simply pass the array directly to `prompt()`.

#### `RunContext::fromTask()`

Accepts a structured task array and sets both the serialized prompt and the `data` bag from the same source:

```php
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$context = RunContext::fromTask([
    'document_id' => $document->id,
    'tenant_id'   => $tenant->id,
])
->withLabels(['tenant_id' => $tenant->id]);
```

This is the factory to use when you want to dispatch with an array task and also attach labels or details in the same expression. The `durable-waits-and-signals` examples use this pattern.

#### `RunContext::fromPayload()`

Rebuilds a `RunContext` from a fully serialized queue payload. Used internally by the durable runner; you will not normally call this directly.

## Property Reference

### `$runId`

**Type:** `string`

A UUID string that uniquely identifies this run. Auto-generated via `str()->uuid()` if you do not provide one.

Set it yourself when you need idempotent dispatch — for example, when a webhook delivery might retry and you want to guarantee only one run exists per event:

```php
$context = RunContext::from(
    'Write a blog post about Laravel queues.',
    runId: 'webhook-event-' . $event->id,
);
```

### `$input`

**Type:** `string`

The original task prompt as a string. When you pass an array to `prompt()` or `RunContext::fromTask()`, the array is JSON-encoded and stored here. Agents receive `$input` as their prompt unless a previous step has written to `data['last_output']`, in which case `prompt()` returns `last_output` instead.

### `$data`

**Type:** `array<string, mixed>`

An associative array for carry-forward state between agents. This is where the swarm runtime stores `last_output` after each sequential step, making the previous agent's response available as the next agent's prompt. When you pass an array to `prompt()` directly, the array is stored in `$data` as well as serialized into `$input`.

Use `$data` for information that agents or your application code need to act on during the run — structured task keys, intermediate computed values, or accumulated step outputs. `$data` is available on `$response->context->data` after a synchronous `prompt()` call.

### `$metadata`

**Type:** `array<string, mixed>`

Observability and operator data attached to the run. `$metadata` is written to persistence records, lifecycle events, and history rows. It is the right place for trace IDs, user IDs, request IDs, campaign tags, or other correlation values that belong in your observability pipeline but should not influence agent logic.

`$metadata` is also the internal storage for labels and details — `withLabels()` and `withDetails()` write into `metadata['durable_labels']` and `metadata['durable_details']` respectively. The `label()`, `detail()`, `labels()`, and `details()` accessors read those keys back out.

### `$artifacts`

**Type:** `array<int, SwarmArtifact>`

Explicit artifacts accumulated during the run. Each `SwarmArtifact` has a `name`, `content`, `metadata`, and optional `stepAgentClass`. You can pre-load artifacts before dispatch with `addArtifact()`, and the swarm runtime may append automatic `agent_output` artifacts when output capture is enabled.

Artifacts are available on `$response->artifacts` and `$response->context->artifacts` after a `prompt()` call. See [Persistence And History](persistence-and-history.md) for storage behavior.

## Mutation Methods

All mutation methods return `$this` for chaining.

### `mergeData(array $values): self`

Merges additional keys into `$data`. Use this when you want to carry extra state forward without replacing keys already present:

```php
$context->mergeData(['draft_id' => $draft->id, 'revision' => 2]);
```

### `mergeMetadata(array $values): self`

Merges additional keys into `$metadata`. Useful for attaching trace or correlation data after construction:

```php
$context->mergeMetadata(['trace_id' => $request->header('X-Trace-Id')]);
```

### `addArtifact(SwarmArtifact $artifact): self`

Appends an artifact to the `$artifacts` list:

```php
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;

$context->addArtifact(new SwarmArtifact(
    name: 'source_document',
    content: ['document_id' => $document->id, 'version' => $document->version],
    metadata: ['kind' => 'reference'],
));
```

### `withLabels(array $labels): self`

Attaches durable run labels. Labels are indexed and queryable in the durable runtime tables, making them useful for filtering runs in `swarm:recover`, operator dashboards, or `swarm:inspect`. Keys must be non-empty strings; values must be scalar or null.

```php
$context->withLabels([
    'tenant_id'   => $tenant->id,
    'document_id' => $document->id,
    'priority'    => 'high',
]);
```

Multiple calls merge into the existing label set. Labels are stored inside `$metadata['durable_labels']` and read back through `labels()` and `label($key)`.

### `withDetails(array $details): self`

Attaches structured detail data for run inspection. Details are displayed by `swarm:inspect` and exposed through `DurableSwarmManager::inspect()`. Use them for rich display data that is too verbose for labels — document titles, user display names, workflow descriptions, or nested metadata shapes.

```php
$context->withDetails([
    'document' => [
        'id'    => $document->id,
        'title' => $document->title,
    ],
    'requester' => $user->name,
]);
```

Details are stored inside `$metadata['durable_details']` and read back through `details()` and `detail($key)`.

## data vs metadata vs labels vs details

These four bags overlap in purpose but differ in when and how they are used.

**`$data`** is for information that agents and the swarm runtime act on. It crosses step boundaries: each sequential agent receives the output of the previous agent through `data['last_output']`, and your application can read accumulated step state from `$response->context->data` after the run. Store task keys, intermediate results, and anything an agent's prompt depends on here.

**`$metadata`** is for observability. It is attached to lifecycle events, history records, and persistence rows, so your logging, tracing, and monitoring pipelines can correlate swarm activity with application requests. Agents do not receive `$metadata` as part of their prompt. Store trace IDs, user IDs, request IDs, campaign markers, and other correlation handles here.

**`$labels`** are a durable-only subset of `$metadata`. They are stored in the `swarm_durable_labels` table as typed columns, making them efficient predicates for list views, `swarm:recover` filters, and operator tooling. Use labels for values that operators or dashboards need to filter on: tenant IDs, document IDs, priority tiers, workflow names. Labels must be scalar.

**`$details`** are a durable-only subset of `$metadata`. They are stored in the `swarm_durable_details` table and displayed by `swarm:inspect`. Use details for nested or verbose display data that does not need to be a SQL predicate. Labels are for filtering; details are for display.

A value might live in more than one bag intentionally. A `tenant_id` that needs filtering goes in labels. If you also want it visible in `swarm:inspect`, add a details entry. If you want it in lifecycle events, put it in `$metadata` too.

## Accessing Context in Agents

Agents in a sequential or parallel swarm receive a string prompt — the previous agent's output or the original task. The full `RunContext` object is not injected into agents automatically.

After a synchronous `prompt()` call, the final context is available on the response:

```php
$response = BlogPostSwarm::make()->prompt([
    'topic' => 'Laravel queues',
    'tone'  => 'technical',
]);

// The context after all agents have run:
$context = $response->context;

$context->runId;             // string UUID
$context->data;              // carry-forward data, including last_output
$context->artifacts;         // any artifacts attached or accumulated during the run
```

For durable runs, the context is reloaded from the database at each step. You can read it back through `SwarmHistory` or `DurableSwarmManager::inspect()` by run ID:

```php
use BuiltByBerry\LaravelSwarm\Facades\SwarmHistory;

$run = SwarmHistory::find($runId);
// $run['context'] carries the final context payload as an array
```

Swarms that implement `RoutesDurableWaits` receive the live `RunContext` in `durableWaits(RunContext $context)`, giving them access to labels, details, and data when deciding which wait declarations apply to a specific run. This is the documented injection point where a swarm's own logic reads the context during execution.

## Signal and Wait Access

For durable runs that use waits and signals, the context carries signal payloads and wait outcomes after a run is resumed. Use these two methods when agent or application logic needs to branch on the result of an external signal:

### `signalPayload(string $name): mixed`

Returns the payload array sent with the named signal, or `null` if no matching signal has been recorded:

```php
$approval = $context->signalPayload('approval_received');

if (is_array($approval) && $approval['approved'] === true) {
    // proceed with the approved path
}
```

### `waitOutcome(string $name): ?DurableWaitOutcome`

Returns a `DurableWaitOutcome` for the named wait, or `null` if no outcome has been recorded yet:

```php
$outcome = $context->waitOutcome('approval_received');

if ($outcome?->timedOut) {
    // the wait expired before a signal arrived
}

if ($outcome?->status === 'signalled') {
    // a signal was received; $outcome->payload carries the signal data
}
```

`DurableWaitOutcome` has four readonly properties:

| Property | Type | Description |
| --- | --- | --- |
| `name` | `string` | The wait name passed to `waitOutcome()` |
| `status` | `string` | `'signalled'`, `'timed_out'`, or `'unknown'` |
| `payload` | `mixed` | The signal payload, or `null` if timed out |
| `timedOut` | `bool` | `true` when the wait expired before a signal arrived |

See [Durable Waits And Signals](durable-waits-and-signals.md) for the full wait and signal lifecycle.

## Serialization

`RunContext` crosses serialization boundaries when you use `queue()`, `dispatchDurable()`, or any execution mode that passes context to a queue worker. The following rules apply:

**What survives a queue or durable boundary:**

- All values in `$data`, `$metadata`, `$labels`, and `$details` that are plain data: strings, integers, floats, booleans, null, and arrays of those types.
- `$input` is stored as-is (it is always a string).
- `$runId` is always preserved.
- `$artifacts` survive if their `content` is plain data.

**What does not survive:**

- Objects, including `JsonSerializable` implementations, public-property DTOs, and enums.
- Closures and anonymous functions.
- Open file handles, database connections, or other resources.
- PHP-internal types.

Laravel Swarm validates plain-data constraints at dispatch time and throws a `SwarmException` before the queue job is written if the context contains non-serializable values.

When `swarm.persistence.encrypt_at_rest` is `true` (the default for database persistence), the serialized `input` and context payload are sealed with Laravel's encrypter before being written to the database. Rotating `APP_KEY` without re-encrypting existing rows leaves those rows unreadable.

## Common Patterns

### Passing structured input and reading it back

Pass task fields as an array and read the accumulated context after the run:

```php
$response = BlogPostSwarm::make()->prompt([
    'topic'    => 'Laravel queues',
    'audience' => 'intermediate developers',
    'tone'     => 'technical',
    'word_count' => 1200,
]);

// The original input is JSON-encoded and stored on the context:
$response->context->input;
// => '{"topic":"Laravel queues","audience":"intermediate developers",...}'

// The task array is also stored in data:
$response->context->data['topic'];      // 'Laravel queues'
$response->context->data['word_count']; // 1200

// The final agent output is in data['last_output'] and also in $response->output:
$response->output;
$response->context->data['last_output'];
```

### Using metadata for request correlation

Attach trace and user context so lifecycle events and history rows carry correlation handles:

```php
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$context = RunContext::from('Summarize this document for a compliance review.')
    ->mergeMetadata([
        'trace_id'   => $request->header('X-Trace-Id'),
        'user_id'    => $request->user()->id,
        'request_id' => $request->id(),
    ]);

$response = ComplianceSummarySwarm::make()->prompt($context);
```

These values appear in `SwarmStarted`, `SwarmCompleted`, and history records. They do not affect the prompt any agent receives.

### Using labels for durable run filtering

Attach labels when operators or dashboards need to filter runs by tenant, document, or workflow:

```php
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$response = ApprovalSwarm::make()->dispatchDurable(
    RunContext::fromTask(['document_id' => $document->id])
        ->withLabels([
            'tenant_id'   => $tenant->id,
            'document_id' => $document->id,
            'workflow'    => 'regulatory-approval',
        ])
        ->withDetails([
            'document' => [
                'id'    => $document->id,
                'title' => $document->title,
            ],
        ])
);

$runId = $response->runId;
```

The labels are stored as typed columns in `swarm_durable_labels` and can be used as efficient predicates when querying for runs belonging to a specific tenant or document. The details are stored in `swarm_durable_details` and displayed when an operator runs `swarm:inspect $runId`. See [Durable Waits And Signals](durable-waits-and-signals.md) for the full operator pattern.

## See Also

- [Structured Input](structured-input.md) — choosing between strings, arrays, and `RunContext`
- [Persistence And History](persistence-and-history.md) — how context is stored and queried
- [Durable Execution](durable-execution.md) — checkpointed execution and context reloading
- [Durable Waits And Signals](durable-waits-and-signals.md) — labels, details, signal payloads, and wait outcomes
