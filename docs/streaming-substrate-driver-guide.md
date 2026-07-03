# Streaming Substrate Driver Guide

`CausalLogStore` and `ColdArchiveDriver` (both public since v0.17.0, #349) are
the extension points for a custom storage backend behind the streaming
substrate's hot/cold tiering. This guide is for **driver authors** — package
maintainers implementing a custom persistence backend — not application
authors declaring swarms (see the
[Streaming Substrate Author Guide](streaming-substrate-author-guide.md) for
that audience).

Read this before implementing either contract: both are narrower than they
might look, and the gap between "public contract" and "full custom driver" is
the thing this guide exists to make explicit.

## What's actually pluggable

Both contracts cover the **read/query seam** only:

- `CausalLogStore` (extends `StreamEventStore`) is read by durable per-node
  streaming's void-on-resume path and by hierarchical stream causal-log
  resolution.
- `ColdArchiveDriver` is read by `TieredStreamEventStore`, which stitches cold
  events below the base pointer with hot events at/above it.

Bind your implementation the same way any contract is bound, in your service
provider:

```php
$this->app->singleton(CausalLogStore::class, MyCausalLogStore::class);
$this->app->singleton(ColdArchiveDriver::class, MyColdArchiveDriver::class);
```

## What's NOT pluggable (yet)

**Compaction does not consume your implementation.** `SwarmCompactor`
constructs `DatabaseCausalLogStore` and `DatabaseColdArchiveDriver`
concretely — not through the container, not through the interface. Rebinding
`CausalLogStore::class` / `ColdArchiveDriver::class` to your own
implementation has no effect on compaction: hot storage for your driver's
runs is never graduated to cold, and there is no error or warning telling you
so. If you need bounded hot-table growth today, your driver needs its own
retention story outside compaction (or you keep hot storage on the database
driver and use a custom `ColdArchiveDriver` only for the cold read path).

**`#[DurableStreaming]` per-node streaming requires the concrete database
implementation.** `DispatchValidator::ensureDurableStreamingInfrastructure()`
checks `instanceof DatabaseCausalLogStore` and throws a `SwarmException` if
that check fails — a swarm opted into `#[DurableStreaming]` with a custom
`CausalLogStore` bound will fail loud at dispatch, not silently degrade. This
is deliberate: fail loud is the safe direction here, but it does mean a
custom driver cannot back this feature today.

**`ColdArchiveDriver::readSnapshot()` has no production caller yet.** It's
part of the contract (a forthcoming snapshot-based resume feature reads it),
and it's exercised directly by tests, but nothing in the framework calls it
today. Implement it to the documented semantics below, but don't expect
end-to-end verification against a real resume flow until that feature lands.

## Semantics your implementation must honor

### `ColdArchiveDriver::readSnapshot()` — decrypt-or-throw

`readSnapshot()` returns the raw (possibly sealed/encrypted) string — it does
not decrypt. The convention (mirroring `DatabaseColdArchiveDriver`) is:

- Return `null` only when nothing has been graduated yet for the run.
- A driver/network failure throws — never returns `null` to mean "something
  went wrong."
- Decryption and the wrong/rotated-key fail-loud path (`openStrict()` +
  `DecryptException` → `SwarmException`) are the **caller's** responsibility,
  not `readSnapshot()`'s. If you write a convenience wrapper analogous to
  `DatabaseColdArchiveDriver::readSnapshotStrict()`, it must fail loud on a
  bad key, never silently return `null` or garbage.

### `ColdArchiveDriver::basePointer()` — the hot/cold boundary contract

The base pointer is an **atomicity guarantee to readers**, not just a number:
a reader observing a non-zero base pointer is guaranteed that all events with
id below it are durably available via `readEvents()`, and that the snapshot
(if any) was written before the pointer advanced. Your implementation only
needs to serve reads correctly against whatever your own write path
guarantees — the atomic swap itself is the internal compactor's obligation,
not yours, since `graduate()`/`reclaim()` aren't part of this interface.

### `CausalLogStore` — database-only by design

`CausalLogStore` extends `StreamEventStore` with void-edge operations
(`appendVoidEdge()`, `sealRollup()`, `voidNodeAttempt()`, `isSealed()`,
`latestAttemptEpochBelow()`) that need indexed UUID lookup and row-level
locking. If your backend can't provide that, implement the base
`StreamEventStore` contract instead (already public) and skip
`CausalLogStore` — a non-`CausalLogStore` `StreamEventStore` binding is a
supported, first-class configuration; it just doesn't get the causal
void-edge / durable-per-node-streaming features.

## Summary

| Capability | Custom `CausalLogStore`/`ColdArchiveDriver`? |
| --- | --- |
| Hierarchical stream read / causal-log resolution | Yes |
| Hot/cold read stitching (`TieredStreamEventStore`) | Yes |
| Compaction (graduation to cold, hot reclaim) | No — concrete-class-coupled |
| `#[DurableStreaming]` per-node streaming | No — fails loud at dispatch |
| Snapshot-based resume (`readSnapshot()`) | Contract-ready, no production caller yet |

If your use case needs compaction or `#[DurableStreaming]` support for a
custom backend, that's follow-on work, not something this promotion covers —
open an issue describing your backend and the gap.
