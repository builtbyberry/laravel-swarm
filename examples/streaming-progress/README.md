# Streaming Progress

Shows how to consume stream events from a swarm.

Use this pattern when a controller or SSE endpoint needs progress as the swarm
runs.

This example teaches:

- `stream()` yields step and token events;
- Laravel 13's `eventStream()` can send those events to the browser;
- streaming is for live progress, not durable storage;
- use lifecycle events or persisted history when the app needs inspection after
  the request ends.

## Stream From A Route

```php
use App\Ai\Swarms\ContentPipeline;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Route;

Route::get('/article-stream', function () {
    return response()->eventStream(function () {
        foreach (ContentPipeline::make()->stream([
            'topic' => 'Laravel events',
            'audience' => 'application developers',
        ]) as $event) {
            yield new StreamedEvent($event['event'], $event);
        }
    });
});
```

## Notes

- Keep streamed task input plain data.
- Do not rely on stream output as durable storage.
- Use persistence if the application needs inspection after the request ends.
- Use capture flags when streamed events may include sensitive prompts or
  outputs.
- A stream is a request/response experience. If the browser also needs a stable
  run detail page, use lifecycle events and a run inspector endpoint keyed by
  the persisted `run_id`.
- `eventStream()` is the Laravel 13-friendly default. If your frontend needs
  custom SSE framing, headers, or `[DONE]` messages, Laravel's normal
  `response()->stream()` is also appropriate.
