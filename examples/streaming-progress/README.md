# Streaming Progress

Shows how to consume stream events from a swarm.

Use this pattern when a controller or SSE endpoint needs progress as the swarm
runs.

This example teaches:

- `stream()` yields step and token events;
- Laravel 13's `eventStream()` can send those events to the browser;
- streaming is for live progress, not durable storage.

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
