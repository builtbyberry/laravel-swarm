# Sequential Blog Pipeline

The "hello world" of Laravel Swarm. Three agents run in order. Each agent's
reply becomes the next agent's prompt.

```
OutlineWriter → Drafter → Polisher
```

## Run it

```bash
php artisan swarm:example:blog-pipeline "Laravel queue visibility timeouts"
```

You should see the polished output from `Polisher`. The intermediate replies
are recorded in `$response->steps`.

## What it demonstrates

- The Swarm contract: a class that returns `agents(): array`.
- Sequential topology (the default).
- The `Runnable` trait and `Swarm::make()->prompt(...)` execution.
- Plain-data task input — a simple string here, but arrays work too.
- The ScriptedAgent base class that ships in `BuiltByBerry\LaravelSwarm\Testing`
  so this example runs end-to-end with no provider configured and no API key.

No queue worker, no database persistence, no audit sink — this is the
minimum viable swarm.

## Plug in a real model

Each agent under `app/Ai/Agents/SequentialBlogPipeline/` extends
`ScriptedAgent`. To use a live LLM, swap that base for the normal Laravel AI
shape:

```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
class OutlineWriter implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Draft a five-point outline for a blog post on the given topic.';
    }
}
```

The swarm class itself does not change.

## Next step

- [docs/sequential.md](../../../docs/sequential.md) — the full sequential topology contract.
- [docs/execution-modes.md](../../../docs/execution-modes.md) — when to move beyond `prompt()`.
