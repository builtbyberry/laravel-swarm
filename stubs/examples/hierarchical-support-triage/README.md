# Hierarchical Support Triage

Classify an incoming support request, then route it to the one handler that
should answer — using a coordinator-owned route plan.

```
                          ┌──> BillingResponder
request ──> RequestClassifier ──┼──> TechnicalResponder ──> $response->output
          (coordinator plan)    └──> GeneralResponder
```

## Run it

```bash
php artisan swarm:example:support-triage "I was double charged on my last invoice."
php artisan swarm:example:support-triage "The app crashes on login."
```

The first request routes to `BillingResponder`; the second to
`TechnicalResponder`. The command prints which handler answered and its reply.

## What it demonstrates

- Hierarchical topology: the first agent is a **coordinator**, the rest are
  **workers** it routes to.
- The coordinator returns a structured **route plan** (`start_at` + `nodes`) —
  there is no separate `route()` callback.
- Classify-then-dispatch: only the routed handler runs, so a billing request
  never touches the technical handler.
- A `finish` node that returns the chosen handler's output as the swarm result.

No queue worker required. No database persistence. No external API keys — every
agent extends the package-shipped `ScriptedAgent`, so the example runs
immediately after install. The classifier's route decision is a deterministic
keyword match so the demo is reproducible.

## Plug in a real model

The coordinator under `app/Ai/Agents/HierarchicalSupportTriage/` is a
`ScriptedAgent` that also implements `HasStructuredOutput`. To route with a live
model, swap it to the Laravel AI shape while keeping the structured-output
contract:

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class RequestClassifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Classify the request, then route it to exactly one handler.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'start_at' => $schema->string()->required(),
            'nodes' => $schema->object()->required(),
        ];
    }
}
```

The coordinator **must** implement `HasStructuredOutput` and declare the
route-plan shape. The handler agents can move to plain `Promptable` agents
independently. See `docs/hierarchical-routing.md`.

## Next step

- [docs/hierarchical-routing.md](../../../docs/hierarchical-routing.md) — full
  hierarchical contract: node types, named outputs, bounded loops, validation
  rules, and execution modes.
- [docs/execution-modes.md](../../../docs/execution-modes.md) — moving from
  `prompt()` to `queue()` and durable hierarchical runs.
