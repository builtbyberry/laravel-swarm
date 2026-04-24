# Structured Input

Laravel Swarm accepts the same three task shapes across `run()`, `queue()`, and
`stream()`:

- a string
- an array
- a `RunContext`

Most applications will use a string or array.

## Passing A String

Use a string when one prompt is enough:

```php
$response = ArticlePipeline::make()->run('Draft a blog outline about Laravel queues.');
```

## Passing An Array

Use an array when the task has a few distinct pieces of input:

```php
$response = ArticlePipeline::make()->run([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
    'goal' => 'blog outline',
]);
```

The same shape works with `queue()` and `stream()`:

```php
ArticlePipeline::make()->queue([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
    'goal' => 'blog outline',
]);

foreach (ArticlePipeline::make()->stream([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
    'goal' => 'blog outline',
]) as $event) {
    //
}
```

Laravel Swarm stores array input as structured task data and makes it available
through the run context during execution. In a hierarchical swarm, the
coordinator still receives the serialized task as its prompt, and the run
context remains available to the swarm runtime while it validates and executes
the returned route plan. In sequential and parallel swarms, the array is
serialized as the prompt each agent receives.

Array task input must be JSON-encodable plain data. Laravel Swarm rejects
resources, closures, and other opaque runtime values instead of serializing PHP
internals into the prompt.

## Using Run Contexts

Use `RunContext` when you need more explicit control, such as setting the run
ID yourself or attaching metadata:

```php
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$response = ArticlePipeline::make()->run(RunContext::from([
    'input' => 'Draft a blog outline about Laravel queues.',
    'data' => ['topic' => 'Laravel queues'],
    'metadata' => ['campaign' => 'content-calendar'],
], 'article-outline-run'));
```

Most applications will not need to construct a `RunContext` manually. Arrays
are the normal structured-input path.

## Queueing Structured Input

Queued structured input crosses a serialization boundary.

When you queue a swarm with an array or `RunContext`, Laravel Swarm serializes
the task as plain queue-safe data and rebuilds the `RunContext` on the worker
before execution.

That means queued task payloads should contain normal serializable values:

- strings
- integers
- arrays
- booleans
- null

Do not rely on closures, open resources, or other opaque runtime values
crossing the queue boundary.

## Choosing Between The Three

Use a string when the task is naturally one prompt.

Use an array when the task has a few named pieces of input and you want them
to stay structured.

Use `RunContext` when you need explicit control over run IDs, metadata, or an
already-built context.

If you are unsure, start with a string. Move to an array when your task
naturally has named parts. Reach for `RunContext` only when you need explicit
control over run metadata.
