# Artifacts

Artifacts are named, typed pieces of content that agents produce during a run. They can be captured automatically from agent outputs or added explicitly via `RunContext`. After a run completes, artifacts are available on the `SwarmResponse` and optionally persisted to the artifact repository — a cache or database store that you can query by run ID long after the PHP process has finished.

## What Artifacts Are

Each artifact is a `SwarmArtifact` value object with four fields:

| Field | Type | Description |
| --- | --- | --- |
| `name` | `string` | A short identifier such as `agent_output` or `research_report`. |
| `content` | `mixed` | Any JSON-serializable value: a string, number, array, or nested structure. |
| `metadata` | `array<string, mixed>` | Optional key/value bag for provenance data such as step index, usage, or content type. |
| `stepAgentClass` | `string\|null` | The fully-qualified agent class that produced the artifact, when applicable. |

Artifacts are attached to the run as a flat list on `RunContext` during execution and surfaced on both the `SwarmResponse` and each `SwarmStep` once execution completes.

## Automatic Artifacts

When `capture.artifacts` is enabled, Laravel Swarm automatically creates one `agent_output` artifact for every completed agent step. This happens inside `SwarmStepRecorder` after each step finishes.

Enable automatic artifact capture in `config/swarm.php`:

```php
'capture' => [
    'outputs' => true,         // required: artifact capture depends on output capture
    'artifacts' => env('SWARM_CAPTURE_ARTIFACTS', false),
],
```

Or with an environment variable:

```bash
SWARM_CAPTURE_OUTPUTS=true
SWARM_CAPTURE_ARTIFACTS=true
```

> **Note:** `capture.artifacts` only activates when `capture.outputs` is also `true`. If output capture is off, artifact capture is silently skipped regardless of the `artifacts` flag. Both defaults are `false` so prompts and outputs are not persisted unless you opt in.

Each automatic artifact has:

- `name`: `agent_output`
- `content`: the step output string (subject to `limits.max_output_bytes` and the overflow strategy)
- `metadata`: includes `index` (step position), `usage` (token counts for that agent), and any truncation markers added by the payload limit check
- `stepAgentClass`: the fully-qualified class name of the agent that produced the output

For a three-agent sequential swarm, a completed run produces three `agent_output` artifacts — one per step.

## Explicit Artifacts

Add artifacts from within a swarm or any collaborator that has access to `RunContext`:

```php
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$context->addArtifact(new SwarmArtifact(
    name: 'research_report',
    content: [
        'summary' => 'Findings on queue visibility timeouts...',
        'sources' => ['https://laravel.com/docs/queues'],
    ],
    metadata: [
        'kind'    => 'structured_report',
        'version' => 1,
    ],
    stepAgentClass: ResearchAgent::class,
));
```

`RunContext::addArtifact()` appends to the `artifacts` array and returns `$this` for chaining:

```php
public function addArtifact(SwarmArtifact $artifact): self
```

You can also attach artifacts before a run starts by constructing a `RunContext` explicitly:

```php
$context = RunContext::from([
    'input' => 'Produce a detailed research report on Laravel queue internals.',
    'metadata' => ['tenant_id' => 'acme'],
]);

$context->addArtifact(new SwarmArtifact(
    name: 'source_document',
    content: ['document_id' => 9871],
    metadata: ['kind' => 'reference'],
));

$response = ContentPipelineSwarm::make()->prompt($context);
```

The `SwarmArtifact` constructor signature is:

```php
public function __construct(
    public readonly string $name,
    public readonly mixed $content,
    public readonly array $metadata = [],
    public readonly ?string $stepAgentClass = null,
)
```

`content` must be a JSON-serializable value (string, integer, float, boolean, null, or a plain array of those). Objects, resources, and closures are rejected before persistence.

## Accessing Artifacts from SwarmResponse

After calling `prompt()`, all artifacts accumulated during the run are available on the `SwarmResponse`:

```php
$response = ContentPipelineSwarm::make()->prompt([
    'topic'    => 'Laravel queue visibility timeouts',
    'audience' => 'intermediate developers',
]);

// All artifacts from the entire run
foreach ($response->artifacts as $artifact) {
    echo $artifact->name;           // e.g. 'agent_output', 'research_report'
    echo $artifact->stepAgentClass; // e.g. App\Ai\Agents\ResearchAgent
    // $artifact->content is the stored value
    // $artifact->metadata is the provenance array
}
```

Step-level artifacts are also available on each `SwarmStep`:

```php
foreach ($response->steps as $step) {
    foreach ($step->artifacts as $artifact) {
        echo "[{$step->agentClass}] artifact: {$artifact->name}";
    }
}
```

When `capture.artifacts` is disabled, `$response->artifacts` and `$step->artifacts` are empty arrays. The response `output` and the step `output` fields are not affected by artifact capture — they always reflect the actual agent outputs in the current process.

## Artifact Persistence

Artifacts are stored through the `ArtifactRepository` contract. Storage happens automatically when `capture.artifacts` is enabled. Two drivers are available.

### Capture config

```php
// config/swarm.php
'capture' => [
    'inputs'         => env('SWARM_CAPTURE_INPUTS', false),
    'outputs'        => env('SWARM_CAPTURE_OUTPUTS', false),
    'artifacts'      => env('SWARM_CAPTURE_ARTIFACTS', false),
    'active_context' => env('SWARM_CAPTURE_ACTIVE_CONTEXT', false),
],
```

`capture.artifacts` — when `true` (and `capture.outputs` is also `true`), an `agent_output` artifact is persisted to the artifact repository after each step.

`capture.active_context` — when `true`, the full `RunContext` including accumulated artifacts is stored in the context store after each step. This is required for `queue()` and `dispatchDurable()` execution.

### Querying persisted artifacts

Use the `ArtifactRepository` contract directly:

```php
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;

$artifacts = app(ArtifactRepository::class)->all($runId);

foreach ($artifacts as $record) {
    // $record['name'], $record['content'], $record['metadata'], $record['step_agent_class']
}
```

`all()` returns an array of raw arrays in insertion order. For history-aware inspection, combine it with `SwarmHistory::find()` — see [Persistence And History](persistence-and-history.md).

### Where artifacts are stored

Artifacts live in the artifact repository store — separate from run history and context. Configure the driver and store under `swarm.artifacts`:

```php
'artifacts' => [
    'driver' => env('SWARM_ARTIFACTS_DRIVER'),        // null inherits from persistence.driver
    'store'  => env('SWARM_ARTIFACTS_STORE'),         // cache store name, or null for default
    'prefix' => env('SWARM_ARTIFACTS_PREFIX', 'swarm:artifacts:'),
],
```

Use `database` for durable storage or `cache` for lightweight recent-run visibility. When the driver is `database`, artifacts are stored in the `swarm_artifacts` table (configurable via `swarm.tables.artifacts`).

## ArtifactRepository Contract

The `ArtifactRepository` contract in `src/Contracts/ArtifactRepository.php` defines two methods:

```php
interface ArtifactRepository
{
    /**
     * @param  array<int, SwarmArtifact|array<string, mixed>>  $artifacts
     */
    public function storeMany(string $runId, array $artifacts, int $ttlSeconds): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(string $runId): array;
}
```

Two implementations ship with the package:

- `CacheArtifactRepository` — stores artifacts as a serialized JSON list in the configured cache store, keyed by `swarm:artifacts:{runId}`.
- `DatabaseArtifactRepository` — inserts one row per artifact into `swarm_artifacts`. Each row carries `run_id`, `name`, `content` (JSON), `metadata` (JSON), `step_agent_class`, `expires_at`, and timestamps.

Bind a custom implementation in a service provider when you need a different storage backend:

```php
$this->app->bind(
    \BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository::class,
    \App\Swarm\CustomArtifactRepository::class,
);
```

The context snapshot (stored via `ContextStore`) also carries the accumulated artifacts array when `capture.active_context` is enabled. Artifacts therefore appear in two independent stores: the artifact repository and the context store. This duplication is intentional so each inspection surface is independently useful.

## Size Limits

`limits.max_output_bytes` applies to artifact content that comes from automatic `agent_output` artifacts. When the serialized output exceeds this limit, the `limits.overflow` strategy decides what happens:

```php
'limits' => [
    'max_output_bytes' => env('SWARM_MAX_OUTPUT_BYTES'),   // null = uncapped
    'overflow'         => env('SWARM_LIMIT_OVERFLOW', 'fail'),  // 'fail' or 'truncate'
],
```

`fail` — the run fails with an exception before the oversized artifact is stored.

`truncate` — the artifact content is truncated and the artifact's `metadata` gains extra fields: `output_truncated` (true), `output_original_bytes`, and `output_stored_bytes`.

Explicit artifacts added via `RunContext::addArtifact()` are not subject to the output limit check. They are validated for plain-data correctness but not size-capped. Artifact content is validated at the time it is added to the context; invalid content (objects, resources, etc.) throws a `SwarmException` before any persistence attempt.

## Durable Execution

In durable runs (`dispatchDurable()`), artifacts accumulate in the context store checkpoint after each step. Each time the durable runner writes a checkpoint, the full `RunContext` — including all artifacts collected so far — is persisted to the context store. If a worker is restarted between steps, the next worker rehydrates the checkpoint and continues from where the previous worker stopped, with all prior artifacts intact.

After a durable run completes, terminal context cleanup (when `capture.active_context` is `false`) replaces the live context snapshot with a redacted version. Use the `ArtifactRepository` directly to query the final artifact list for a completed durable run — those rows are not overwritten by terminal cleanup.

## Testing

Use real execution (not `SwarmFake`) when you need to assert on artifact production. Fakes bypass the runner entirely and do not produce or store artifacts.

Enable capture flags in your test to make artifacts visible:

```php
// In TestCase or a specific test
config()->set('swarm.capture.outputs', true);
config()->set('swarm.capture.artifacts', true);
```

The package `TestCase` already sets all `swarm.capture.*` flags to `true` so artifact behavior is exercised without extra configuration in package tests.

Assert artifacts on the response directly:

```php
use App\Ai\Swarms\ContentPipelineSwarm;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;

it('produces a research_report artifact', function () {
    $response = ContentPipelineSwarm::make()->prompt([
        'topic' => 'Laravel queue visibility timeouts',
    ]);

    // Artifacts on the SwarmResponse
    $report = collect($response->artifacts)
        ->firstWhere('name', 'research_report');

    expect($report)->not->toBeNull();
    expect($report->content)->toHaveKey('summary');
    expect($report->stepAgentClass)->toBe(\App\Ai\Agents\ResearchAgent::class);
});
```

Assert persisted artifacts from the repository:

```php
it('persists agent_output artifacts for each step', function () {
    $response = ContentPipelineSwarm::make()->prompt('research this topic');

    $runId    = $response->metadata['run_id'];
    $stored   = app(ArtifactRepository::class)->all($runId);

    expect($stored)->toHaveCount(3); // one per agent step
    expect($stored[0]['name'])->toBe('agent_output');
    expect($stored[0]['step_agent_class'])->toBe(\App\Ai\Agents\ResearchAgent::class);
});
```

Assert that artifact capture suppression works when the flag is off:

```php
it('omits artifacts from the response when capture.artifacts is disabled', function () {
    config()->set('swarm.capture.artifacts', false);

    $response = ContentPipelineSwarm::make()->prompt('write about queues');

    expect($response->artifacts)->toBe([]);
    expect(app(ArtifactRepository::class)->all($response->metadata['run_id']))->toBe([]);
});
```

For the full fake and assertion API see [Testing](testing.md). For lifecycle events that carry artifact payloads see [Observability](observability-logging-tracing.md).

## Related

- [RunContext](run-context.md) — the envelope that carries input, run identity, and accumulated artifacts through a run
- [Structured Input](structured-input.md) — constructing a `RunContext` with pre-attached artifacts before dispatch
- [Persistence And History](persistence-and-history.md) — driver selection, encryption at rest, capture flags, and payload limits
- [Testing](testing.md) — fake API, persisted run assertions, and lifecycle event assertions
- [Configuration](../README.md#configuration) — full `config/swarm.php` reference
