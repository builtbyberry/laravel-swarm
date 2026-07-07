# Durable Streaming Digest

Per-node **durable streaming**, end to end. A two-step sequential swarm runs in
durable mode with `#[DurableStreaming]`, so each worker node token-streams a
section of a digest and every token delta is persisted to the causal log under
that node's id. After the run completes you can replay the streamed text — per
node, in causal order — straight from the log.

```
ScriptedStreamingEditor (headline) ──stream──▶ swarm_stream_events (node step:0)
ScriptedStreamingEditor (summary)  ──stream──▶ swarm_stream_events (node step:1)
                                                        │
                                          CausalLogView::forRun(...)->fold()
                                                        ▼
                                     reconstructed streamed text, per node
```

## Prerequisites

Durable streaming persists deltas to the database, so this example needs the
database persistence driver — it **cannot run on the array/in-memory driver**.
Dispatch fails loud if the streaming columns are missing.

- `SWARM_PERSISTENCE_DRIVER=database` — durable streaming requires the
  `DatabaseCausalLogStore` and the `swarm_stream_events` streaming columns.
- Package migrations run (`php artisan migrate`).
- A queue worker on the durable connection to advance the run (see the caveat
  below about the demo's in-process shortcut).

`swarm:install` provisions the database driver and migrations for a fresh app.

## Run it

```bash
php artisan swarm:example:streaming run "Weekly engineering digest"
# => Run ID: 01HXY...
# => Status: completed
# => step:0  This week in engineering:
# => step:1  Three PRs merged, zero incidents.
```

A single `php artisan` invocation dispatches the run durably, drains it to
completion, and replays each node's streamed text from the persisted log.

## What it demonstrates

- **`#[DurableStreaming]`** — per-node token streaming persisted to the causal
  log. `durable_streaming` is pinned `true` on the run row at run-start.
- **`dispatchDurable()`, not `->run()`/`->stream()`** — only a durable dispatch
  writes per-node deltas. A live run carrying the same attribute streams to the
  caller but persists **zero** durable rows, leaving nothing to replay. That is
  why the runner dispatches durably.
- **`CausalLogView::forRun($store, $runId)->fold()`** — the documented read
  surface. It folds the append-only log into a per-node event stream;
  `SwarmTextDelta::combine()` concatenates each node's deltas back into text.

## Two caveats to keep this honest

1. **Database driver required.** Durable streaming cannot run on the
   array/in-memory driver — there is no `swarm_stream_events` table to write to,
   and dispatch fails loud. Use the database persistence driver.
2. **The in-process drain is demo-only.** The runner command hand-drives the
   `AdvanceDurableSwarm` job in a loop so the example runs in one process with no
   queue worker. In a real app a **queue worker** advances the run — never
   hand-drive the advance job in production code.
3. **The streamed text is scripted, not model-generated.** `ScriptedStreamingEditor`
   yields a fixed sequence of token deltas offline — no provider, no API key. It
   shows the *shape* of a durable stream (the same "show the shape" caveat as
   `ScriptedAgent`, applied to token deltas). Swap it for a real Laravel AI agent
   whose `stream()` yields live model deltas and the swarm and runner stay
   identical.

## Next step

- [docs/streaming-substrate-author-guide.md](../../../docs/streaming-substrate-author-guide.md) —
  the causal-log substrate and how to fold it for a dashboard.
- [docs/durable-execution.md](../../../docs/durable-execution.md) — the durable
  runtime contract end to end.
