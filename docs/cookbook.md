# Cookbook

Short, copy-paste recipes for the class-free entry points — the fastest way
to run a governed agent or an inline swarm without authoring a `Swarm` class.
Everything here flows through the **same** `SwarmRunner` a hand-written swarm
uses, so audit, guardrails, capture, telemetry, and encrypt-at-rest apply
identically. There is no second, ungoverned path.

Each recipe is deliberately small. When you outgrow one — when the same
composition earns a name, a reused topology, or class-level attributes — the
[decision guide](#decision-guide-front-door-inline-builders-or-a-swarm-class)
at the end shows exactly where the line sits and how to graduate to a full
`Swarm` class.

For the full reference on what each entry point wraps, see
[Execution Modes → Single Agent](execution-modes.md#single-agent-swarmagent)
and [Execution Modes → Inline Swarms](execution-modes.md#inline-swarms-swarmsequential--parallel--hierarchical).

---

## Recipe: Single agent, fully governed

You have exactly one agent and one task. You do not want to write a `Swarm`
class — but you still want the audit trail, the globally configured guardrails,
capture, and telemetry that a swarm gives you. Reach for `Swarm::agent()`.

```php
use BuiltByBerry\LaravelSwarm\Facades\Swarm;

$response = Swarm::agent(new ArticlePlanner)->prompt($task);

$response->output; // string
$response->steps;  // array of SwarmStep
```

`Swarm::agent($agent)` wraps the lone agent in a one-element swarm and runs it
through the exact pipeline a multi-agent swarm uses. A swarm of one is still a
swarm: the app's globally configured guardrails (`swarm.guardrails.*`) apply
automatically, the run is recorded in history, and every execution mode is
available.

### Every execution mode works

The same verbs you use on a `Swarm` class work here — the mode is chosen at the
call site, exactly as it is for a class-based swarm:

```php
Swarm::agent(new ArticlePlanner)->prompt($task);          // synchronous
Swarm::agent(new ArticlePlanner)->queue($task);           // background
Swarm::agent(new ArticlePlanner)->stream($task);          // SSE token stream
Swarm::agent(new ArticlePlanner)->broadcast($task, $ch);  // push to Echo/Reverb
Swarm::agent(new ArticlePlanner)->dispatchDurable($task); // checkpointed
```

A single agent runs under the default sequential topology, which is a
pass-through for one agent — so `stream()` and the broadcast helpers are
available here without the topology constraint that applies to multi-agent
parallel and hierarchical swarms.

### Layer per-call guardrails

Governance is on by default. `->guardrails([...])` is **additive** — it layers
per-call guardrails on top of the globally configured ones rather than
replacing them:

```php
use App\Ai\Guardrails\BudgetGuardrail;

Swarm::agent(new ArticlePlanner)
    ->guardrails([BudgetGuardrail::class])
    ->prompt($task);
```

The global `swarm.guardrails.*` guardrails still run; `BudgetGuardrail` runs in
addition to them for this call only.

---

## Recipe: Inline swarms without a class

You have a multi-agent workflow but it is a one-off — a composition you will
call from a single place and never reuse. The inline builders let you pin a
topology and run it without declaring a class. Each builder runs through the
same `SwarmRunner`, so the inline swarm is governed identically to a class-based
one and exposes every execution mode.

### Sequential — each agent feeds the next

```php
use BuiltByBerry\LaravelSwarm\Facades\Swarm;

$response = Swarm::sequential([
    new OutlineWriter,
    new Drafter,
    new Polisher,
])->prompt($task);
```

The first agent receives the task; its output becomes the second agent's input,
and so on down the chain. This is the inline equivalent of the
[sequential blog pipeline](getting-started.md#what-just-happened) starter — the
same behavior, no class file.

### Parallel — every agent runs the same task

```php
$response = Swarm::parallel([
    new SecurityReviewer,
    new PerformanceReviewer,
    new StyleReviewer,
])->prompt($task);
```

Every agent receives the original task and runs concurrently; all results are
collected into the single `SwarmResponse`.

### Hierarchical — a coordinator routes over workers

The first argument is the coordinator; the second is the pool of workers it
routes over. The coordinator runs first, reads the task, and returns a dynamic
route plan deciding which workers execute:

```php
$response = Swarm::hierarchical(
    new SupportCoordinator,        // coordinator (runs first, returns the route plan)
    [new BillingAgent, new TechnicalAgent, new AccountAgent], // workers
)->prompt($task);
```

### Guardrails and modes, same as `Swarm::agent()`

`->guardrails([...])` is additive here too, and the execution-mode verbs are
identical:

```php
use App\Ai\Guardrails\BudgetGuardrail;

Swarm::parallel([new SecurityReviewer, new PerformanceReviewer])
    ->guardrails([BudgetGuardrail::class])
    ->dispatchDurable($task);
```

**One constraint to know:** `stream()` and the broadcast helpers require a
single ordered event stream, so they are limited to sequential swarms. Inline
`parallel()` and `hierarchical()` swarms support `prompt()`, `queue()`, and
`dispatchDurable()`, but not streaming — the same rule that applies to
class-based parallel and hierarchical swarms. See
[Execution Modes](execution-modes.md#stream) for the reasoning.

---

## Decision guide: front door, inline builders, or a Swarm class?

The class-free entry points and a hand-authored `Swarm` class produce the same
governed run. The choice is about naming, reuse, and configuration — not about
governance, which is identical either way.

| You have… | Reach for | Why |
|---|---|---|
| One agent, one task, no reuse | `Swarm::agent($agent)` | Full governance without a class file. |
| A multi-agent composition you call from one place | `Swarm::sequential()` / `parallel()` / `hierarchical()` | Pin a topology inline; no class to name. |
| A topology you reuse across the app | A `Swarm` class | A name and a single definition to import everywhere. |
| A workflow that needs class-level attributes | A `Swarm` class | `#[Timeout]`, `#[MaxAgentSteps]`, `#[DurableRetry]`, `#[Topology]` live on the class. |
| Agents assembled dynamically at runtime | The inline builders | Build the agent array in code, then hand it to a builder. |

### Choose the inline entry points when

- **It is a one-off.** The composition is invoked from a single place and does
  not benefit from a name.
- **You are prototyping.** You want to see a workflow run before committing to a
  class and a test.
- **The agent list is dynamic.** You assemble the agents at runtime (from
  configuration, a database, or user input) and pass the array straight to a
  builder.
- **You still want the audit trail.** This is the whole point — the class-free
  path is not an escape hatch from governance. Everything is captured, audited,
  and guardrailed exactly as a class-based run would be.

### Author a `Swarm` class when

- **The same topology is reused across your app.** A named class gives you one
  definition to import everywhere, instead of repeating the agent list at every
  call site.
- **You need class-level attributes.** `#[Timeout]`, `#[MaxAgentSteps]`,
  `#[DurableRetry]`, and `#[Topology]` are declared on the class. The inline
  builders pin topology but do not carry these.
- **The workflow deserves a name.** A `ContentPipeline` class documents intent
  in a way an inline `Swarm::sequential([...])` at a controller cannot.
- **You want the fake/assert testing ergonomics.** A named class supports
  `ContentPipelineSwarm::fake()` and `assertPrompted(...)` — see
  [Testing](testing.md).

Graduating is cheap. An inline `Swarm::sequential([$a, $b, $c])` becomes a class
by moving the agent array into an `agents()` method and adding a `#[Topology]`
attribute — the call site changes from `Swarm::sequential([...])->prompt($task)`
to `ContentPipeline::make()->prompt($task)`, and every execution mode keeps
working unchanged. See [Getting Started → Scaffold your own swarm](getting-started.md#scaffold-your-own-swarm)
and [Generators](generators.md) for the scaffolding.

---

## Related

- [Execution Modes](execution-modes.md) — the full reference for `Swarm::agent()`,
  the inline builders, and every execution mode.
- [Getting Started](getting-started.md) — from `composer require` to a running
  starter swarm.
- [Introduction](introduction.md) — the three concepts, topologies, and
  execution modes at a glance.
- [Sequential](sequential.md) · [Parallel](parallel.md) ·
  [Hierarchical Routing](hierarchical-routing.md) — the per-topology deep dives.
- [Guardrails](guardrails.md) — how global and per-call guardrails compose.
- [Testing](testing.md) — the fake/assert layer for class-based swarms.
