# Parallel Research Fanout

Dispatch a single prompt to multiple research agents at once and merge the
results into a single response.

```
                  ┌──> MarketScout ──┐
input topic ──────┼──> CompetitorScout ─┼──> $response->steps (3 items)
                  └──> CustomerScout ──┘
```

## Run it

```bash
php artisan swarm:example:research-fanout "AI agent orchestration for Laravel apps"
```

You should see three labeled blocks of output — one per scout. They were
produced concurrently and joined in declaration order.

## What it demonstrates

- Parallel topology: every agent receives the *original* task input.
- Laravel Concurrency for in-process fan-out.
- The container-resolvable agent contract that parallel workers require.
- Iterating over `$response->steps` to merge / display per-agent output.

No queue worker required. No database persistence. No external API keys —
each scout extends the package-shipped `ScriptedAgent` so the example runs
immediately after install.

## Plug in a real model

Each scout under `app/Ai/Agents/ParallelResearchFanout/` extends
`ScriptedAgent`. To use live LLMs, swap to the Laravel AI shape:

```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class MarketScout implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Research the market landscape for the given topic.';
    }
}
```

Parallel agents must stay **stateless** and **container-resolvable** — the
constructor cannot require runtime arguments. See `docs/parallel.md`.

## Next step

- [docs/parallel.md](../../../docs/parallel.md) — full parallel topology contract,
  including the durable parallel variant.
- [docs/execution-modes.md](../../../docs/execution-modes.md) — moving from `prompt()`
  to durable parallel.
