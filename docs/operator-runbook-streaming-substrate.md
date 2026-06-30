# Operator Runbook: Streaming Substrate

This is the operator side of the v0.15.0 streaming substrate. It covers the
mechanics you own — **hot/cold tiering**, the **background compaction worker**,
the **retention horizon**, and **recovery / quarantine** when compaction stalls.

For the author side — streaming dynamic swarms, the context-growth policy, and
rollup nodes — see the
[Streaming Substrate Author Guide](streaming-substrate-author-guide.md).

Everything here requires the **database persistence driver** — the causal log,
the cold archive, and the compaction lease all live in database tables. The
cache driver has no causal log to compact; run the substrate on the database
driver.

---

## 1. The Model in One Page

A streamed run's events are written to one hot table, `swarm_stream_events`, in
DB-sequenced append order. Nothing is ever mutated or deleted in place — a
course-correction is a *void-edge* row, not an edit. That table grows for the
life of a run, so the substrate bounds it by **graduating** a sealed prefix to a
cold tier.

Three pieces do that:

- **A seal barrier.** A streaming runner emits a `SwarmCausalSealBarrier`
  immediately after `SwarmStreamEnd` (and mid-run when a
  [rollup](streaming-substrate-author-guide.md#rollup-nodes) seals a window). The
  barrier's DB auto-increment `id` **is** the graduation boundary: everything
  with `id < barrier.id` is sealed and eligible to move to cold. The barrier is
  filtered from replay output and is never visible to stream consumers.
- **A base pointer.** The cold tier records one number per run — the boundary
  below which events live in cold and at/above which they live in hot. It only
  ever moves forward.
- **The tiered store.** `TieredStreamEventStore` reads cold for everything below
  the base pointer and hot for everything at/above it, stitched at a half-open
  seam — no gap, no duplicate. A consumer that reads a run's full history after
  compaction sees the complete timeline and never knows tiering happened.

| Tier | Table | Holds |
| --- | --- | --- |
| Hot | `swarm_stream_events` | The live, retractable window — the newest events plus everything not yet graduated. |
| Cold | `swarm_cold_archives` | Graduated raw events (audit) and a sealed fold snapshot (resume), addressable by run + coordinate. |

Cold storage keeps **two retention paths**: the raw graduated events (for audit
replay) and a sealed fold snapshot (for resume). Resume reads on the snapshot use
`openStrict` (decrypt-or-throw) — the same #212 convention the durable stores
use, so an `APP_KEY` rotation after graduation surfaces a re-dispatchable
`SwarmException` rather than silently returning corrupt data.

---

## 2. Scheduling Compaction

**The package does not auto-schedule compaction.** You must schedule it, or the
hot log grows unbounded. The recommended schedule:

```php
// bootstrap/app.php  (or routes/console.php)
$schedule->command('swarm:compact')->hourly();
```

`swarm:compact` is a **discover-and-dispatch** command. It does not do the
compaction work itself — it finds eligible runs (those with a seal barrier, not
quarantined) and dispatches one `CompactSwarmRun` queue job per run, then reports
`Dispatched <n> compaction job(s).` (or `No runs with unsealed events were
found.`). The actual graduation happens asynchronously on your queue. Each
invocation emits a `command.compact` audit record.

```
swarm:compact {--run-id=} {--limit=50}
```

| Flag | Default | Purpose |
| --- | --- | --- |
| `--run-id=<id>` | — | Target one specific run (used in recovery, below). |
| `--limit=50` | `50` | Max runs to discover and dispatch per sweep. |

Tune the schedule and `--limit` to your event volume: a high-throughput
deployment may want `->everyFifteenMinutes()` and a larger limit; a quiet one is
fine hourly. Because work runs on the queue, ensure a worker is processing the
queue `CompactSwarmRun` is dispatched to.

---

## 3. How a Single Compaction Cycle Works

`SwarmCompactor` drives the per-run cycle inside the queue job. The ordering is
an invariant — **cold-durable → base-pointer advance → reclaim, never inverted** —
so a crash at any point leaves the hot log intact and a re-run is safe.

1. **Acquire a compaction lease.** A CAS on `compaction_token` /
   `compaction_leased_until` in `swarm_durable_runs` ensures only one compactor
   works a run at a time. The lease expires naturally if the job process dies, so
   a crashed compaction self-heals on the next sweep. Tune the hold with
   `SWARM_COMPACTION_LEASE_SECONDS` (default `300`).
2. **Locate the latest barrier** above the current base pointer.
3. **Fold** the events from the base pointer up to the barrier into a sealed
   snapshot.
4. **Graduate** — in one transaction: write the raw events to cold, set
   `sealed_at` on the hot rows, then **CAS-advance** the base pointer to the
   barrier. The CAS makes concurrent advance impossible and keeps the base
   pointer monotonically non-decreasing.
5. **Reclaim** — delete the hot rows below the barrier, but **only** after the
   CAS succeeded. Fail-safe: hot is never reclaimed on doubt.

After reclaim, `TieredStreamEventStore::events()` stitches cold + hot so a
consumer still sees the complete history.

You do not run these steps by hand; this is what the queue job does. The list is
here so that when you read a log line or a quarantine flag you know which stage it
came from.

---

## 4. The Retention Horizon

Two things age out, on two different clocks:

- **Hot rows graduate to cold** on the **compaction** clock — driven by your
  `swarm:compact` schedule, not by a TTL. A row stays hot until a barrier seals
  it and a compaction cycle graduates it.
- **Cold rows and hot rows expire** on the **prune** clock — the existing
  `expires_at` TTL and `swarm:prune` retention. Void-edge rows and the new
  causal-log columns inherit the `swarm_stream_events` TTL; there is no new prune
  hook to wire. See [Maintenance § Pruning](maintenance.md#pruning-expired-records).

So the practical retention horizon for a streamed run is: *live in hot →
graduated to cold by compaction → pruned by `swarm:prune` at its TTL*. If you
need a longer audit window, raise the prune retention; if hot is growing faster
than you like, compact more often (or have authors add
[rollups](streaming-substrate-author-guide.md#rollup-nodes) to seal windows
mid-run).

---

## 5. Context-Growth Budget

The [context-growth policy](streaming-substrate-author-guide.md#context-growth-policy)
is where authors and operators meet. The **author** declares intent with
`#[ContextGrowthPolicy]`; **you** supply the numbers via config:

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `swarm.context_growth.budget_events` | `SWARM_CONTEXT_GROWTH_BUDGET_EVENTS` | `null` (inert) | Soft budget in hot stream events. |
| `swarm.context_growth.hard_cap_events` | `SWARM_CONTEXT_GROWTH_HARD_CAP_EVENTS` | `null` (disabled) | Absolute ceiling that refuses the run regardless of declared policy. |
| `swarm.context_growth.policy` | `SWARM_CONTEXT_GROWTH_POLICY` | `degrade_to_cold` | Framework default rung when a swarm declares none. |
| `swarm.context_growth.backpressure_delay_ms` | `SWARM_CONTEXT_GROWTH_BACKPRESSURE_DELAY_MS` | `250` | Bounded delay under the `backpressure` rung. |

Two operator facts worth internalizing:

- **The budget defaults to `null` and is inert.** The package ships the
  machinery and the author's intent, never an imposed number. **Supplying a
  budget activates the framework default `degrade_to_cold`** (a `CompactSwarmRun`
  nudge per over-budget run) unless an author declared a different rung — setting
  a number is your opt-in to the default behaviour, not just to measurement.
- **The hard cap is best-effort governance, not a correctness invariant.** A
  breach refuses the run (re-dispatchable `ContextBudgetExceededException`), but
  if the policy machinery itself fails, the run proceeds rather than wedging. The
  working set is measured **per stream segment** (it resets when a run resumes in
  a fresh process), so the budget and hard-cap are enforced per segment, not
  across a run's full history.

Every over-budget evaluation emits a `context_growth.action` telemetry event
attributing the action to the declared policy — wire it into your telemetry sink
to watch which swarms are pushing the budget.

> The `degrade_to_cold` nudge can only reclaim a **sealed** prefix. Within a
> single non-rollup run there may be nothing sealed until completion, so the nudge
> materialises at run end (or mid-run once a rollup seals a window). For timely
> live-growth control, point authors at `backpressure` or `refuse`.

---

## 6. Recovery: A Run Is Quarantined

If a run's graduation throws repeatedly, the compactor **quarantines** it rather
than crash-looping: it sets `compaction_quarantined_at` in `swarm_durable_runs`,
logs a `warning`, and excludes the run from future sweeps. The run keeps
executing and replaying normally — only its hot-log compaction is paused. Nothing
is lost: the fail-safe ordering means hot was never reclaimed.

You will notice this as a `warning` log entry and as a run whose hot event count
stops shrinking.

### Step 1 — Read the warning

The quarantine log entry carries the `run_id` and the `exception_class`. Common
causes:

- **`APP_KEY` rotated after a prior graduation** — a strict (`openStrict`) cold
  snapshot read can no longer decrypt. See
  [APP_KEY Rotation](app-key-rotation.md).
- **Cold storage unavailable or misconfigured** — the cold driver could not write.
- **A corrupt or oversized payload** at the graduation boundary.

### Step 2 — Resolve the underlying cause

Fix what the exception class points at — restore the key, repair the cold store
binding, or address the payload. Do **not** clear the flag until the cause is
fixed, or the next cycle will quarantine the run again.

### Step 3 — Clear the flag and re-trigger

```php
DB::table('swarm_durable_runs')
    ->where('run_id', $runId)
    ->update(['compaction_quarantined_at' => null]);
```

```bash
php artisan swarm:compact --run-id=<run_id>
```

This dispatches a fresh `CompactSwarmRun` for that one run. If it graduates
cleanly, the run rejoins normal sweeps; if it throws again, it re-quarantines and
you repeat from Step 1 with the new exception class.

---

## 7. Rolling-Deploy Note (v0.14.x → v0.15.0)

The forward-compatibility sentinel that lets a worker skip an unknown persisted
event type (`SwarmUnknownEvent`) was **not** backported to v0.14.x. A v0.15.0
worker begins writing `swarm_causal_seal_barrier` rows immediately after the
first `SwarmStreamEnd`; a v0.14.x worker that later resumes that same run will hit
the barrier row and throw.

- **Safe path:** a coordinated full-fleet restart — all workers on v0.15.0 before
  any long-running durable run completes.
- **In practice:** a standard rolling deploy that finishes within one compaction
  window (default lease `300 s`) is safe, since the window is short.

From v0.15.0 onward the sentinel protects you: a future package version's new
event types will not crash a co-deployed v0.15.0 worker.

---

## 8. Quick Reference

| Symptom | Likely cause | Action |
| --- | --- | --- |
| Hot table growing unbounded | `swarm:compact` not scheduled, or queue worker not draining `CompactSwarmRun` | Schedule the command; confirm a worker processes the job's queue. |
| A run's hot count stopped shrinking | Run quarantined | §6 — read the warning, fix the cause, clear `compaction_quarantined_at`, `swarm:compact --run-id=`. |
| `swarm:compact` exits with a no-op message | Cache persistence driver | Compaction requires the database driver; nothing to do. |
| Quarantine `exception_class` is a decrypt error | `APP_KEY` rotated after graduation | [APP_KEY Rotation](app-key-rotation.md), then §6. |
| v0.14.x worker throwing after a v0.15.0 stream | Pre-sentinel worker hit a seal barrier | §7 — finish the fleet upgrade. |

---

## Related

- [Streaming Substrate Author Guide](streaming-substrate-author-guide.md) — dynamic streaming, growth policy, rollups
- [Streaming](streaming.md) — `stream()`, replay, crash-replay durability
- [Maintenance](maintenance.md) — scheduling, pruning, retention
- [APP_KEY Rotation](app-key-rotation.md) — rotating the key alongside sealed rows
- [Public Surface](public-surface.md#streaming-substrate-v0150) — the substrate contracts and classes
