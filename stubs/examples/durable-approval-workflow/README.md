# Durable Approval Workflow

The showcase example. A two-step sequential swarm runs in **durable** mode
with a `policy_decision` checkpoint between the steps. The runner parks the
run until a human signals approve or reject.

```
PolicyDraftAgent ──> [wait: policy_decision] ──> PolicyFinalizeAgent
```

## Prerequisites

A real-app run needs more than the first two examples:

- `SWARM_PERSISTENCE_DRIVER=database`
- `SWARM_CAPTURE_ACTIVE_CONTEXT=true`
- Package migrations run (`php artisan migrate`)
- A queue worker running on the durable connection
- `swarm:recover` scheduled for wait timeouts

`swarm:install` provisions all of these for a fresh app.

## Run it

```bash
# Step A — kick off a durable run.
php artisan swarm:example:approval-workflow start "Enable 2FA for all admins"
# => Run ID: 01HXY...

# Step B — when the worker has drained the first job and the run is waiting,
#          signal a decision.
php artisan swarm:example:approval-workflow signal 01HXY... approve

# Step C — inspect at any time.
php artisan swarm:example:approval-workflow status 01HXY...
```

## What it demonstrates

- `dispatchDurable()` — checkpointed execution that survives worker crashes.
- The `RoutesDurableWaits` contract — declarative human-in-the-loop pauses.
- `DurableSwarmManager::signal()` — the resume primitive. The shipped
  `php artisan swarm:signal <run-id> <name>` command is equivalent and is
  what most operators reach for in production.
- `#[DurableLabels]` and `#[DurableDetails]` — surfaces that the operator
  commands (`swarm:status`, `swarm:history`, `swarm:inspect`,
  `swarm:trace`) and the Pulse cards read for triage.
- `#[Timeout]` — the orchestration deadline for the whole run.

## Plug in a real model

`PolicyDraftAgent` and `PolicyFinalizeAgent` extend `ScriptedAgent`. To use a
live LLM, swap each to a normal Laravel AI agent that uses `Promptable`. The
swarm class, the wait declaration, and the runner command stay identical.

## Next step

- [docs/durable-execution.md](../../../docs/durable-execution.md) — the durable runtime
  contract end to end.
- [docs/durable-waits-and-signals.md](../../../docs/durable-waits-and-signals.md) — the
  full wait/signal contract, including idempotency keys and timeouts.
