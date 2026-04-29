# Streaming Progress

Shows how to consume stream events from a swarm. The canonical reference is
[`docs/streaming.md`](../../docs/streaming.md).

Use this pattern when a controller or SSE endpoint needs progress as the swarm
runs.

This example teaches:

- `stream()` yields typed step, text, reasoning, and tool events;
- stream responses can be returned directly from controllers;
- stream replay storage is opt-in when exact playback is needed later;
- use lifecycle events or persisted history when the app needs inspection after
  the request ends.

## Stream From A Route

```php
use App\Ai\Swarms\ContentPipeline;
use Illuminate\Support\Facades\Route;

Route::get('/article-stream', function () {
    return ContentPipeline::make()->stream([
        'topic' => 'Laravel events',
        'audience' => 'application developers',
    ]);
});
```

`toResponse()` uses Laravel AI-style `data:` SSE lines and a final `[DONE]`.
If you want Laravel 13 named SSE events, each swarm stream event also exposes
`toStreamedEvent()` for use with `response()->eventStream()`.

## Persist Exact Stream Replay

```php
use App\Ai\Swarms\ContentPipeline;
use BuiltByBerry\LaravelSwarm\Facades\SwarmHistory;
use Illuminate\Support\Facades\Route;

Route::get('/article-stream', function () {
    return ContentPipeline::make()
        ->stream([
            'topic' => 'Laravel events',
            'audience' => 'application developers',
        ])
        ->storeForReplay();
});

Route::get('/article-stream/{runId}/replay', function (string $runId) {
    return SwarmHistory::replay($runId);
});
```

## Notes

- Keep streamed task input plain data.
- Persisted stream replay is disabled by default. Use `storeForReplay()` for a
  single response or `SWARM_STREAM_REPLAY_ENABLED=true` globally.
- Use persistence if the application needs inspection after the request ends.
- Use capture flags when streamed events may include sensitive prompts or
  outputs.
- Final-agent non-text events (`swarm_reasoning_*`, `swarm_tool_*`,
  `swarm_text_end`) are persisted and replayed in emitted order when replay is
  enabled.
- A stream is a request/response experience. If the browser also needs a stable
  run detail page, use lifecycle events, persisted replay, and a run inspector
  endpoint keyed by the persisted `run_id`.
