# Sequential Conversation Memory

Share a fact between agents through **Swarm memory**, not just the prompt chain.
Two agents run in order:

```
RequestListener → ReplyWriter
```

`RequestListener` reads the customer's message, pulls out the subject (a support
reference like `HD-2291`, or a short summary), and **remembers** it. Its own
reply deliberately leaves the subject out. `ReplyWriter` then **recalls** the
subject and writes a reply that names it.

Because step one's output never mentions the subject, the only channel that can
carry it to step two is memory. That is the point: **a value written in an
earlier step demonstrably shapes a later one.**

## Run it

```bash
php artisan swarm:example:conversation-memory "Following up on ticket HD-2291 — the export still times out."
```

You should see step one's subject-free acknowledgement, then a reply from
`ReplyWriter` that references `HD-2291` — a value it could only have gotten from
memory.

## What it demonstrates

- **The `remember` / `recall` memory-as-tool surface**, the headline feature.
  `RequestListener` calls the real `Remember` tool; `ReplyWriter` calls the real
  `Recall` tool — the same tools a live model would call mid-prompt.
- **Run scope and default propagation.** The write lands in the **Run** scope —
  the run's own shared context. The default propagation policy presents
  Run-scoped memory to every later agent in the run, so a within-run handoff
  needs no custom policy. Recall reads *through* that policy, so it only ever
  surfaces memory the agent is permitted to see.
- **Memory vs the prompt chain.** In a sequential swarm each agent's output is
  the next agent's input. Keeping the subject out of step one's output isolates
  memory as the channel that carried it — the smoke test asserts exactly this.
- Sequential topology, the `Runnable` trait, and `Swarm::make()->prompt(...)`.
- The `ScriptedAgent` base class from `BuiltByBerry\LaravelSwarm\Testing`, so the
  example runs end-to-end with no provider and no API key. The scripted replies
  invoke the same production `remember` / `recall` tools a model would, so the
  demo genuinely reflects the remembered value rather than a hardcoded string.

### A note on replay

Under the default `FrozenView` replay mode, a crash-resumed final step recalls
against the memory snapshot frozen at its original invocation — which already
contains step one's write, since step one completed first. So the recalled
subject, and the reply it shapes, stay identical across a replay. Nothing extra
is needed here; it is worth knowing the guarantee holds.

## Plug in a real model

Generate native `RequestListener` and `ReplyWriter` classes with
`php artisan make:agent`, then port the instructions and expose `[new Remember]`
or `[new Recall]` from their generated `tools()` methods. The model then decides
when to call those tools; the deterministic offline starter calls them directly.

## Next step

- [docs/memory.md](../../../docs/memory.md) — scopes, propagation, and capture.
- [docs/memory-recipes.md](../../../docs/memory-recipes.md) — including
  **Conversation** scope, which shares memory across separate runs of the same
  thread (it needs a conversation id bound to the run — the cross-run counterpart
  to the within-run handoff shown here).
- [docs/sequential.md](../../../docs/sequential.md) — the sequential topology contract.
