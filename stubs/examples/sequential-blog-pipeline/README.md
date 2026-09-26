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

Generate one native Laravel AI class per model agent, then port the starter's
instructions:

```bash
php artisan make:agent OutlineWriter
php artisan make:agent Drafter
php artisan make:agent Polisher
```

Reference those generated classes from the swarm. See
`docs/native-agent-onboarding.md` for the native fake and streaming path.

## Next step

- [docs/sequential.md](../../../docs/sequential.md) — the full sequential topology contract.
- [docs/execution-modes.md](../../../docs/execution-modes.md) — when to move beyond `prompt()`.
