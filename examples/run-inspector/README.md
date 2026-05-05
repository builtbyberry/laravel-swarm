# Run Inspector

Shows how to build an application status endpoint for a swarm run.

Use this pattern when the browser needs a stable run detail page after a
queued, streamed, synchronous, or durable swarm has started.

This example teaches:

- `run_id` is the application handle for persisted inspection;
- history, context, artifacts, and durable state are related but separate
  storage surfaces;
- a queued run may need a short-lived pending record before the worker writes
  history;
- the inspector is application code, not a package-owned dashboard API.

## Prerequisites

- Use database persistence when run inspection must outlive cache TTLs.
- Store a small pending record when a controller returns a `run_id` before the
  worker writes history.
- Enable durable persistence when the inspector should include durable waits,
  branches, labels, details, progress, or child runs.

## Pending Run Store

When a controller queues work and immediately returns `202`, the queue worker
may not have written history yet. Store a small pending record so your UI can
show a status page right away.

### `app/Support/PendingSwarmRunStore.php`

```php
<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class PendingSwarmRunStore
{
    public function __construct(
        protected CacheFactory $cache,
        protected ConfigRepository $config,
    ) {}

    public function put(string $runId, string $input, string $swarmClass, string $topology): void
    {
        $this->cache->store()->put($this->key($runId), [
            'run_id' => $runId,
            'input' => $input,
            'swarm_class' => $swarmClass,
            'topology' => $topology,
            'status' => 'queued',
        ], (int) $this->config->get('swarm.context.ttl', 3600));
    }

    public function find(string $runId): ?array
    {
        $pending = $this->cache->store()->get($this->key($runId));

        return is_array($pending) ? $pending : null;
    }

    protected function key(string $runId): string
    {
        return 'swarm:pending:'.$runId;
    }
}
```

## Inspector Service

The package stores the run in several focused repositories. Your application can
compose those repositories into the shape your UI needs.

### `app/Support/SwarmRunInspector.php`

```php
<?php

namespace App\Support;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use Illuminate\Support\Str;

class SwarmRunInspector
{
    public function __construct(
        protected RunHistoryStore $history,
        protected ContextStore $context,
        protected ArtifactRepository $artifacts,
        protected DurableRunStore $durableRuns,
        protected PendingSwarmRunStore $pendingRuns,
    ) {}

    public function find(string $runId): ?array
    {
        $history = $this->history->find($runId);
        $context = $this->context->find($runId);
        $pending = $this->pendingRuns->find($runId);
        $durable = $this->durableRuns->find($runId);
        $artifacts = $this->artifacts->all($runId);

        if ($history === null && $context === null && $pending === null && $durable === null && $artifacts === []) {
            return null;
        }

        $context = is_array($context) ? $context : [];
        $historyContext = is_array($history['context'] ?? null) ? $history['context'] : [];
        $context = $context !== [] ? $context : $historyContext;

        $swarmClass = $history['swarm_class'] ?? $durable['swarm_class'] ?? $pending['swarm_class'] ?? null;

        return [
            'run_id' => $runId,
            'status' => $history['status'] ?? $durable['status'] ?? ($pending !== null ? 'queued' : 'running'),
            'swarm_class' => $swarmClass,
            'swarm_name' => is_string($swarmClass) ? Str::headline(class_basename($swarmClass)) : null,
            'topology' => $history['topology'] ?? $durable['topology'] ?? $pending['topology'] ?? null,
            'input' => $context['input'] ?? $pending['input'] ?? null,
            'output' => $history['output'] ?? ($context['data']['last_output'] ?? null),
            'steps' => $history['steps'] ?? [],
            'artifacts' => $artifacts !== [] ? $artifacts : ($history['artifacts'] ?? []),
            'metadata' => array_merge(
                is_array($context['metadata'] ?? null) ? $context['metadata'] : [],
                is_array($history['metadata'] ?? null) ? $history['metadata'] : [],
            ),
            'durable' => $durable,
            'error' => $history['error'] ?? null,
        ];
    }
}
```

## Controller Endpoints

```php
use App\Ai\Swarms\ContentPipeline;
use App\Support\PendingSwarmRunStore;
use App\Support\SwarmRunInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

public function store(Request $request, PendingSwarmRunStore $pendingRuns): JsonResponse
{
    $topic = $request->validate([
        'topic' => ['required', 'string', 'min:3', 'max:200'],
    ])['topic'];

    $response = ContentPipeline::make()->queue([
        'topic' => $topic,
    ]);

    $pendingRuns->put($response->runId, $topic, ContentPipeline::class, 'sequential');

    return response()->json([
        'run_id' => $response->runId,
        'status' => 'queued',
        'run_url' => route('swarm.runs.show', $response->runId),
    ], 202);
}

public function show(string $runId, SwarmRunInspector $inspector): JsonResponse
{
    $run = $inspector->find($runId);

    abort_if($run === null, 404);

    return response()->json($run);
}
```

```php
use App\Http\Controllers\SwarmRunController;
use Illuminate\Support\Facades\Route;

Route::post('/swarms/content-pipeline', [SwarmRunController::class, 'store']);
Route::get('/swarms/runs/{runId}', [SwarmRunController::class, 'show'])
    ->name('swarm.runs.show');
```

## Notes

- Keep the pending record short-lived; persisted history becomes the source of
  truth once the worker starts.
- The inspector should not assume every run is durable. Durable state is
  present only for `dispatchDurable()`.
- Capture flags still apply to persisted input, output, artifacts, and failure
  messages. Your inspector should display `[redacted]` as an intentional value.
