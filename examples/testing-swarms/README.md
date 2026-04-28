# Testing Swarms

Shows the two main testing styles: fake interaction tests and real persisted
execution tests.

This example teaches:

- use fakes for normal application tests;
- use persisted assertions when history, artifacts, or database behavior matter;
- use event assertions for real lifecycle behavior;
- array task assertions use subset matching.

## Fake A Swarm

```php
use App\Ai\Swarms\ContentPipeline;

ContentPipeline::fake(['draft complete']);

$response = ContentPipeline::make()->run([
    'topic' => 'Laravel testing',
    'audience' => 'package developers',
]);

expect((string) $response)->toBe('draft complete');

ContentPipeline::assertRan([
    'topic' => 'Laravel testing',
]);
```

## Queue Assertions

```php
use App\Ai\Swarms\ContentPipeline;

ContentPipeline::fake();

ContentPipeline::make()->queue('Draft the article.');

ContentPipeline::assertQueued('Draft the article.');
```

The same fake can assert streamed and durable dispatches:

```php
ContentPipeline::fake(['streamed']);

iterator_to_array(ContentPipeline::make()->stream('Draft the article.'));

ContentPipeline::assertStreamed('Draft the article.');
ContentPipeline::assertNeverDispatchedDurably();
```

```php
ContentPipeline::fake();

ContentPipeline::make()->dispatchDurable([
    'topic' => 'Laravel durable workflows',
]);

ContentPipeline::assertDispatchedDurably([
    'topic' => 'Laravel durable workflows',
]);
```

## Persisted Execution

```php
use App\Ai\Swarms\ContentPipeline;

config()->set('swarm.persistence.driver', 'database');

$response = ContentPipeline::make()->run([
    'topic' => 'Laravel persistence',
]);

ContentPipeline::assertPersisted($response->metadata['run_id'], 'completed');
ContentPipeline::assertPersisted(['topic' => 'Laravel persistence']);
```

Use persisted assertions when the test is about history, artifacts, events, or
database behavior. Use fakes for normal application tests.

## Lifecycle Events

Use `InteractsWithSwarmEvents` when the test needs to inspect real events:

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Testing\InteractsWithSwarmEvents;

class ContentPipelineTest extends TestCase
{
    use InteractsWithSwarmEvents;
}

ContentPipeline::make()->run('Draft the article.');

ContentPipeline::assertEventFired(
    SwarmCompleted::class,
    fn ($event) => $event->executionMode === 'run',
);
```
