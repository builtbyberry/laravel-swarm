# Swarm Memory

Swarm Memory is the first-class, scoped, snapshot-replayable memory subsystem shipped in v0.9.0. It gives agents and application code a place to read and write structured values that persist across steps, survive queue boundaries, and can be replayed deterministically from a frozen snapshot on a crash-resume.

The primary surface is the `SwarmMemory` contract, resolved from the container or via `app(SwarmMemory::class)`. The `RunContext` object — the envelope every swarm run carries — writes through to memory automatically, so you can start using memory without any wiring change.

---

## Scope hierarchy

Every memory entry lives in exactly one scope. The scope determines the lifetime and visibility of the entry, and pairs with a `scopeId` (the concrete run ID, conversation ID, agent class, or swarm class) to form the full address.

```
Swarm scope  ──── shared across all agents in a swarm class
  │
  └── Conversation scope  ──── shared across runs in the same conversation thread
        │
        └── Run scope  ──── bounded to a single swarm run
              │
              └── Agent scope  ──── per-agent-class persistent state
```

| Scope | `MemoryScope` case | `scopeId` | Lifetime |
| --- | --- | --- | --- |
| **Run** | `MemoryScope::Run` | The `runId` string | Cleared with the run |
| **Conversation** | `MemoryScope::Conversation` | A conversation thread ID | Shared across runs |
| **Agent** | `MemoryScope::Agent` | The agent class (FQCN) | Per-agent persistent state |
| **Swarm** | `MemoryScope::Swarm` | The swarm class (FQCN) | Shared across all agents in a swarm |

**v0.9.0 note:** The snapshot mechanism (see [Snapshot mechanism](#snapshot-mechanism) below) captures only `MemoryScope::Run` entries for each agent invocation. Reads against the other three scopes during replay fall through to the live store and fire a `MemoryScopeOutOfSnapshot` event.

---

## Reading and writing

Resolve `SwarmMemory` from the container and call the value-shaped methods:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

$memory = app(SwarmMemory::class);

// Write a value — inserts or updates by (scope, scopeId, key)
$entry = $memory->put(
    MemoryScope::Run,
    $runId,
    'draft_approved',
    true,
);

// Read a value — returns the raw value or null when absent
$approved = $memory->get(MemoryScope::Run, $runId, 'draft_approved');

// Read the full entry when you need metadata or timestamps
$entry = $memory->entry(MemoryScope::Run, $runId, 'draft_approved');
$entry?->createdAt;   // CarbonImmutable
$entry?->updatedAt;   // CarbonImmutable
$entry?->metadata;    // array<string, mixed>

// Delete a single entry — returns true when a row was removed
$existed = $memory->forget(MemoryScope::Run, $runId, 'draft_approved');

// Retrieve every entry under a (scope, scopeId)
$entries = $memory->all(MemoryScope::Run, $runId);
// => array<int, MemoryEntry>
```

All four methods are covered by the `SwarmMemory` contract. Custom drivers and test doubles can implement the contract without touching any other class.

### Scope-bounded query example

Reading all Run-scoped entries for a completed run:

```php
$entries = app(SwarmMemory::class)->all(MemoryScope::Run, $response->context->runId);

foreach ($entries as $entry) {
    echo "{$entry->key}: " . json_encode($entry->value) . "\n";
}
```

Reading a per-agent persistent value (knowledge that should survive across runs):

```php
$tone = app(SwarmMemory::class)->get(
    MemoryScope::Agent,
    WriterAgent::class,
    'preferred_tone',
);
```

---

## Value objects

### `MemoryEntry`

`MemoryEntry` is the immutable value object returned by `put()`, `entry()`, and `all()`. It carries the full address plus the persisted value and metadata.

| Property | Type | Description |
| --- | --- | --- |
| `scope` | `MemoryScope` | The addressing scope |
| `scopeId` | `string` | The concrete ID within the scope |
| `key` | `string` | The entry name |
| `value` | `mixed` | Plain data: string, int, float, bool, null, or nested arrays of those types |
| `metadata` | `array<string, mixed>` | Implementation annotations (capture policy outcome, source, etc.) |
| `createdAt` | `?CarbonImmutable` | Populated by the store on first write |
| `updatedAt` | `?CarbonImmutable` | Populated by the store on every write |

`value` must be plain data. The store normalizes it before persistence; non-serializable values (objects, closures, resources) are rejected.

### `MemoryScope`

```php
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

MemoryScope::Run          // 'run'
MemoryScope::Conversation // 'conversation'
MemoryScope::Agent        // 'agent'
MemoryScope::Swarm        // 'swarm'
```

---

## Propagation policy

When a runner invokes a worker agent, a **propagation policy** decides which memory entries that agent sees. The policy runs at the snapshot chokepoint — the same point where the agent-visible view is frozen — so the persisted `MemorySnapshot` mirrors exactly what the policy permitted. Every runner (sequential, parallel, hierarchical, and the durable/queued path) consults it.

The contract has two methods. `scopes()` declares *what to load* — the runner gathers candidates from exactly those scopes and no others, so a policy never pays to read a scope it ignores. `present()` is the *what to show* filter: drop, reorder, or narrow by key or age within the gathered candidates.

```php
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

final class SharedSwarmStatePolicy implements MemoryPropagationPolicy
{
    /**
     * Load Run- and Swarm-scoped candidates; ignore everything else.
     *
     * @return array<int, MemoryScope>
     */
    public function scopes(): array
    {
        return [MemoryScope::Run, MemoryScope::Swarm];
    }

    /**
     * @param  array<int, MemoryEntry>  $candidateEntries
     * @return array<int, MemoryEntry>
     */
    public function present(array $candidateEntries, RunContext $context, ?Agent $agent): array
    {
        // Already scoped to Run + Swarm by scopes(); pass through unchanged.
        return $candidateEntries;
    }
}
```

`present()` receives the candidates gathered from the scopes `scopes()` declared, the live `RunContext`, and the target `Agent` (null on the durable and hierarchical-parallel paths, where only the agent class is known at freeze time). It returns the ordered subset the agent may see. Implementations must not fabricate or mutate entries — they may only drop and reorder. Note: the `Agent` scope is gathered only when the concrete agent instance is known, and the `Conversation` scope is not yet gatherable (no conversation handle exists) — declaring it is a no-op for now.

### Default behavior

The default `DefaultPropagationPolicy` presents the **Run-scoped view only**, which is exactly what runners froze and agents saw before v0.10. Unmodified swarms are unaffected: no package code writes to the Conversation, Agent, or Swarm scopes during a run, so the default view is byte-identical to pre-v0.10 behavior.

### Choosing a policy

Bind a global default in a service provider or via config:

```php
// config/swarm.php
'memory' => [
    'propagation_policy' => \App\Memory\SharedSwarmStatePolicy::class,
],
```

```bash
# or via env
SWARM_MEMORY_PROPAGATION_POLICY="App\\Memory\\SharedSwarmStatePolicy"
```

Override for a single swarm with the `#[PropagationPolicy]` attribute (the class is resolved through the container, so it may declare its own dependencies):

```php
use BuiltByBerry\LaravelSwarm\Attributes\PropagationPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

#[PropagationPolicy(SharedSwarmStatePolicy::class)]
class ResearchSwarm implements Swarm
{
    // ...
}
```

The attribute takes precedence over the config-bound default. Widening the view is a **semantic** change — downstream agents may see more memory than they did before — but it is never an API change, and the default keeps the v0.9 behavior.

---

## Capture policy (write-time redaction)

Where the propagation policy decides what an agent *reads*, the **capture policy** decides what gets *written*. `MemoryCapturePolicy` is consulted at the write boundary and returns a `CaptureDecision` per `(scope, key)`:

- **`Full`** — persist the value unchanged (the default for every write).
- **`Redact`** — persist the entry with scalar values replaced by the `SwarmCapture::REDACTED` sentinel (`'[redacted]'`), preserving array structure and keys so the entry stays addressable. This is the same sentinel the audit capture path uses.
- **`Skip`** — drop the entry entirely: no row is written and no `MemoryWritten` event fires. Skip suppresses *this* write only — any pre-existing entry at the address is left untouched (it is not deleted).

This is the write-side counterpart to the audit `CapturePolicy` (`swarm.capture.*`): redacting here keeps PII out of memory in the first place, so it never reaches a frozen `MemorySnapshot`. Like the audit policy, a capture policy **never receives the value** — only the scope and key — so a decision cannot couple to payload shape or leak unredacted data.

Enforcement lives in the `RedactingMemoryStore` decorator the container wraps around your memory driver (via `$app->extend(MemoryStore::class, …)`), so **every** write flows through one chokepoint — including a custom or companion driver you bind yourself. (Bind it with `bind()`/`singleton()`, not `Container::instance()`, so the decorator still wraps it.) Reads return already-redacted values, so the propagation view and frozen snapshots inherit redaction with no extra work.

> **Scope.** Redaction applies at the persistence boundary and covers the entry **value** only — not the entry **`metadata`** (which carries functional annotations like `source`/`usage`) and not the **key** (keys are addressing; redacting them would break `get`/`all`). Don't put PII in memory metadata or keys. A run's own in-process `RunContext` also still holds the raw value it just wrote until the run ends; the policy governs what is *persisted*, snapshotted, and visible to other agents.

Each non-`Full` decision is observable: a `Redact` write dispatches `MemoryRedacted` (alongside the usual `MemoryWritten`), and a `Skip` dispatches `MemoryWriteSkipped`. Both carry only the entry address (`scope`, `scopeId`, `key`), never the value — subscribe to them for an audit trail of capture-policy actions.

The default `DefaultMemoryCapturePolicy` returns `Full` for everything, preserving pre-v0.10 behavior. Bind your own globally:

```php
// config/swarm.php
'memory' => [
    'capture_policy' => \App\Memory\RedactSsnPolicy::class,
],
```

```bash
# or via env
SWARM_MEMORY_CAPTURE_POLICY="App\\Memory\\RedactSsnPolicy"
```

```php
use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

final class RedactSsnPolicy implements MemoryCapturePolicy
{
    public function memory(MemoryScope $scope, string $key, ?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return in_array($key, ['ssn', 'card_number'], true)
            ? CaptureDecision::Redact
            : CaptureDecision::Full;
    }
}
```

In tests, `SwarmFake::interceptMemoryCapturePolicy()` installs a `RecordingMemoryCapturePolicy` that records every decision (and optionally wraps a delegate that drives them), so you can assert what the store saw.

---

## Store drivers

The `MemoryStore` contract is the low-level storage driver. `SwarmMemory` delegates persistence to a bound `MemoryStore` implementation.

**`DatabaseMemoryStore`** (default) — persists entries to the `swarm_memories` table introduced in v0.9.0. Survives process restarts, queue boundaries, and durable checkpoints. The recommended driver for any application that uses `queue()` or `dispatchDurable()`.

**`CacheMemoryStore`** — persists entries to the Laravel cache. Suitable for synchronous `prompt()` runs in environments without a database; entries are subject to the cache TTL and are not durable.

### Swapping drivers

Bind a different implementation in a service provider:

```php
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use App\Memory\RedisMemoryStore;

$this->app->bind(MemoryStore::class, RedisMemoryStore::class);
```

Custom drivers must implement `MemoryStore` and dispatch the [memory lifecycle events](#lifecycle-events) from their `put()`, `get()`, and `forget()` methods. The bundled drivers dispatch events unconditionally — see the dispatch-layer note in `DefaultSwarmMemory` for the rationale.

### Installing the memory tables

Run `php artisan swarm:install:memory` (v0.9.0+) to publish and run the `swarm_memories` and `swarm_memory_snapshots` migrations. The command follows the same sentinel-marker pattern as the other sub-installers and is safe to re-run.

---

## Lifecycle events

Swarm Memory dispatches five events through Laravel's event system. Register listeners in `EventServiceProvider` or in a service provider closure.

| Event | When | Key properties |
| --- | --- | --- |
| `MemoryWritten` | After a successful `put()` | `scope`, `scopeId`, `key`, `metadata` |
| `MemoryRead` | After every `get()`, hit or miss | `scope`, `scopeId`, `key` |
| `MemoryForgotten` | After every `forget()` | `scope`, `scopeId`, `key`, `existed` |
| `MemorySnapshotted` | After a per-step snapshot is captured | `runId`, `stepIndex`, `snapshotId` |
| `MemoryScopeOutOfSnapshot` | During replay when a read targets a non-Run scope | `runId`, `stepIndex`, `scope`, `scopeId`, `key`, `operation` |

Events are dispatched at the **store layer**, not from the `SwarmMemory` facade. Custom `MemoryStore` drivers must dispatch them from their own `put()`, `get()`, and `forget()` implementations to keep the listener contract uniform.

`MemoryRead` does not expose the entry value. Listeners that need the value should re-read through the store under their own access controls — this keeps the event surface compatible with [capture-policy redaction](#capture-policy-write-time-redaction), which can redact or drop the value at write.

### Listener examples

**Audit trail — log every Run-scoped write:**

```php
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

Event::listen(MemoryWritten::class, function (MemoryWritten $event): void {
    if ($event->scope !== MemoryScope::Run) {
        return;
    }

    logger()->info('Memory written', [
        'scope'    => $event->scope->value,
        'scope_id' => $event->scopeId,
        'key'      => $event->key,
    ]);
});
```

**Compliance — hard-fail on out-of-snapshot cross-scope reads during replay:**

```php
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryScopeOutOfSnapshot;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

Event::listen(MemoryScopeOutOfSnapshot::class, function (MemoryScopeOutOfSnapshot $event): void {
    throw new SwarmException(
        "Replay determinism violation: {$event->operation} on {$event->scope->value} scope "
        . "at step {$event->stepIndex} of run {$event->runId}."
    );
});
```

Without this listener the event fires silently and the read falls through to the live store — pragmatic enough not to break agents that read shared knowledge, but no persistent record is created. Stricter compliance postures should wire this listener from day one; there is no passive record to fall back on.

**Monitoring — track forget with deletion audit:**

```php
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryForgotten;

Event::listen(MemoryForgotten::class, function (MemoryForgotten $event): void {
    if (! $event->existed) {
        return; // no-op probe — ignore for audit purposes
    }

    logger()->warning('Memory entry deleted', [
        'scope'    => $event->scope->value,
        'scope_id' => $event->scopeId,
        'key'      => $event->key,
    ]);
});
```

---

## Snapshot mechanism

Before every agent invocation, each runner (sequential, parallel, hierarchical, and durable branch) freezes the agent-visible memory view by calling `SnapshotsMemory::snapshot()`. The result — a `MemorySnapshot` — is persisted to the `swarm_memory_snapshots` table keyed by `(run_id, step_index)`.

The snapshot captures:

- **`entries`** — every `MemoryScope::Run` entry visible at the moment of invocation, byte-identical to what the agent saw.
- **`toolCalls`** — input/output pairs for every tool the agent called during the invocation. Appended in real time by the runner after each tool call completes.

### `MemorySnapshot` value object

| Property | Type | Description |
| --- | --- | --- |
| `runId` | `string` | The run the snapshot was taken for |
| `stepIndex` | `int` | Zero-based step index |
| `entries` | `array` | Plain-data entry rows as recorded |
| `toolCalls` | `array` | Tool input/output pairs recorded during the invocation |
| `frozen` | `bool` | `true` on snapshots loaded from persistence; `false` on in-flight writes |

**Inspecting a snapshot** from PHP:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;

$snapshot = app(SnapshotsMemory::class)->find($runId, $stepIndex);

if ($snapshot !== null) {
    foreach ($snapshot->entries as $row) {
        echo "{$row['key']}: " . json_encode($row['value']) . "\n";
    }

    foreach ($snapshot->toolCalls as $call) {
        echo "Tool: {$call['name']}, Result: " . json_encode($call['result']) . "\n";
    }
}
```

**Inspecting a snapshot** from the CLI with `swarm:memory:inspect`:

```bash
# List every snapshot recorded for a run (one row per step).
php artisan swarm:memory:inspect r-abc123

# Expand the frozen view for a single step — entries and tool calls.
php artisan swarm:memory:inspect r-abc123 --step=0

# JSON output for pipelines, audit evidence, and jq.
php artisan swarm:memory:inspect r-abc123 --step=0 --format=json

# Filter the entries view to a single scope. v0.9.0 snapshots freeze
# MemoryScope::Run only, so non-Run scopes will return empty entries
# until the propagation policy lands a wider snapshot.
php artisan swarm:memory:inspect r-abc123 --step=0 --scope=run
```

The command reads the `swarm_memory_snapshots` table directly and works uniformly across all four runners (sequential, parallel, hierarchical, durable branch). Pair it with `swarm:memory:dump` for full-run exports. See the [compliance audit guide](compliance-audit.md) for the broader operator workflow.

### v0.9.0 scope coverage

The snapshot captures only `MemoryScope::Run` entries. Conversation, Agent, and Swarm scope entries are **not** snapshotted — reads against those scopes during replay fall through to the live store, and a `MemoryScopeOutOfSnapshot` event fires for each one. This is a pragmatic trade-off: most agents operate on Run-scoped data, and snapshotting the wider scope graph would require coordinating across runs and agent lifecycles that may be long-lived.

---

## Replay semantics

When a durable run crashes mid-step and is retried, the runner needs to decide what memory the agent sees on the second attempt. Two modes are available, controlled by `swarm.memory.replay_mode`.

### `frozen_view` (default)

The agent re-executes against the `MemoryScope::Run` entries frozen in the snapshot captured at the original invocation. Live writes to Run scope during the retry are buffered and never reach the backing store, preserving the canonical audit record. This is the recommended mode for reproducible, audit-friendly runs.

### `fresh_execution`

The agent re-executes against live memory with no snapshot guard. Use only when idempotency is guaranteed externally and you deliberately want the retry to see any writes that arrived between the original invocation and the retry.

### Configuration

```php
// config/swarm.php
'memory' => [
    'replay_mode' => env('SWARM_MEMORY_REPLAY_MODE', 'frozen_view'),
],
```

```ini
# .env
SWARM_MEMORY_REPLAY_MODE=frozen_view
```

### Per-swarm override with `#[MemoryReplay]`

Override the global setting for a single swarm class using the `#[MemoryReplay]` attribute:

```php
use BuiltByBerry\LaravelSwarm\Attributes\MemoryReplay;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;

#[MemoryReplay(mode: ReplayMode::FreshExecution)]
class MyIdempotentSwarm extends Swarm
{
    // …
}
```

`ReplayMode` cases:

| Case | Value | Behavior |
| --- | --- | --- |
| `ReplayMode::FrozenView` | `frozen_view` | Deterministic replay from frozen snapshot (default) |
| `ReplayMode::FreshExecution` | `fresh_execution` | Live memory, no snapshot guard |

When the attribute is absent the global `swarm.memory.replay_mode` config applies.

### Binding-restore constraint

`MemoryReplayCoordinator::during()` implements the frozen-view swap by resolving the original `SwarmMemory` binding via `app()->make(SwarmMemory::class)`, installing a `ReplaySwarmMemory` decorator via `app()->instance(SwarmMemory::class, $replay)` for the duration of the callback, then restoring the original binding via `app()->instance(SwarmMemory::class, $original)` in a `finally` block.

**Known constraint:** the restore step uses `instance()`, which always registers a singleton. If your application binds `SwarmMemory` as a factory (a non-singleton closure or a transient binding), the restore step silently converts it to singleton behavior for subsequent resolutions. The default binding is a singleton (`DefaultSwarmMemory`), so this does not affect standard setups. If you have customized the `SwarmMemory` binding to be non-singleton, document this trade-off in your service provider and verify the behavior under replay.

---

## RunContext bridge

`RunContext` — the envelope every swarm run carries — writes through to `SwarmMemory` automatically. You do not need to resolve `SwarmMemory` directly to record per-run state; anything written to `RunContext` via `mergeData()` is mirrored into `MemoryScope::Run` for the same run.

### ArrayAccess on RunContext

`RunContext` implements `ArrayAccess` as of v0.9.0. Use it as a direct memory accessor from any code that already holds a context handle:

```php
// Write — mirrors into SwarmMemory::Run scope
$context['approval_status'] = 'pending';

// Read — reads from SwarmMemory::Run scope (falls back to $data array if SwarmMemory is not bound)
$status = $context['approval_status']; // 'pending'

// Check
if (isset($context['draft_id'])) {
    // …
}

// Delete
unset($context['draft_id']);
```

Offset reads are backed by `SwarmMemory::get(MemoryScope::Run, $runId, $key)`. Offset writes call `SwarmMemory::put()` and also update the local `$data` array so prompt interpolation and inter-step relays remain array-fast (Eloquent attribute-caching pattern — the memory store is the write-through target, not the hot-path read source).

### `mergeData()` write-through

The existing `mergeData()` method also mirrors to memory:

```php
$context->mergeData(['summary' => $agentOutput, 'word_count' => $wordCount]);
// Both keys are also written to MemoryScope::Run via SwarmMemory::put()
```

Callers that pass a structured task array to `prompt()` — `BlogPostSwarm::make()->prompt(['topic' => 'AI', 'tone' => 'technical'])` — have always stored that array in `$data`. As of v0.9.0 those same keys are also persisted to memory without any code change.

### Null-bind tolerance

If no `SwarmMemory` is bound in the container (POPO test setups, lightweight unit tests), the write-through path is a no-op. The `$data` array is still updated locally, so all existing test code continues to work without wiring memory.

### How this relates to the memory subsystem

The write-through does not replace direct `SwarmMemory` reads and writes — it complements them. Use `$context['key']` when you already hold a `RunContext` and want to read or write a Run-scoped value as part of the run lifecycle. Use `app(SwarmMemory::class)->get(MemoryScope::Agent, AgentClass::class, 'key')` when you want to read from a different scope or when you are outside the run lifecycle (for example, in a listener or background task).

---

## Pulse observability

When Laravel Pulse is installed, the optional `<livewire:swarm.memory />` card surfaces four signals operators tune retention and capture policy against:

- **Entries written per scope** — count of `put()` calls grouped by `MemoryScope` (Run / Conversation / Agent / Swarm).
- **Average bytes per write** — approximate JSON byte size of the persisted `value` + `metadata`, averaged per scope.
- **Recall hit rate** — `MemoryRead` hits divided by total reads, per scope. Sustained low hit rates often indicate a propagation policy or scope mismatch.
- **Snapshot footprint** — total snapshot count, average bytes per persisted snapshot row, and average entries per snapshot. Ballooning snapshot sizes are an early warning that capture policy is letting too much payload through.

The card is registered automatically by `php artisan swarm:install:pulse` (re-run with `--force` after upgrading to pick up the new card and recorder), or you can wire it manually:

```php
// config/pulse.php
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmMemoryMetrics;

'recorders' => [
    SwarmMemoryMetrics::class => [
        'enabled' => env('PULSE_SWARM_MEMORY_METRICS_ENABLED', true),
    ],
],
```

```blade
{{-- resources/views/vendor/pulse/dashboard.blade.php --}}
<livewire:swarm.memory cols="6" />
```

### Tuning the sample rate

`swarm.pulse.memory.sample_rate` controls how often the recorder samples memory events. Default is `1.0` (record every event). High-volume apps should lower this:

```env
SWARM_PULSE_MEMORY_SAMPLE_RATE=0.1   # ~10% sampling
SWARM_PULSE_MEMORY_SAMPLE_RATE=0.0   # disable entirely
```

Sampling is uniform across writes, reads, and snapshots — so averages reported by the card remain statistically meaningful at any rate above zero. Counts (total entries, total snapshots) scale linearly with the sample rate, so multiply by `1 / sample_rate` if you need an absolute estimate.

### Tuning when the card raises an alarm

The card itself does not raise red-state alarms; it surfaces numbers an operator interprets. The common signals and the knobs that move them:

- **Entries-per-scope growing unbounded** — schedule `php artisan swarm:memory:purge` more aggressively, or tighten the retention window in `swarm.memory.retention.*` (v0.10.0).
- **Average bytes per write climbing** — review `swarm.capture.outputs` and `swarm.capture.artifacts`; tighten the metadata allowlist; reduce the size of values being written through `RunContext` write-through.
- **Snapshot bytes spike with no entries change** — a single agent is appending large tool-call payloads. Inspect the offending run with `php artisan swarm:inspect <run-id>` and consider redacting that tool's input/output via the capture policy.
- **Recall hit rate near 0%** — agents are reading keys that were never written. Confirm the propagation policy (`MemoryPropagationPolicy`, v0.10.0) carries the expected scopes into the worker context.

For aggregate Pulse internals — period selectors, recorder enable flag, troubleshooting — see [Pulse](pulse.md#swarm-memory-card).

---

## See Also

- [RunContext](run-context.md) — the envelope that carries input, identity, and carry-forward data through a run; includes ArrayAccess reference
- [Lifecycle Events](events.md) — every swarm lifecycle event, including memory events
- [Durable Execution](durable-execution.md) — checkpointing, crash-resume, and replay
- [Configuration](configuration.md) — `swarm.memory.replay_mode` and all memory config keys
- [Public Surface](public-surface.md) — `SwarmMemory`, `MemoryEntry`, `MemoryScope`, `MemorySnapshot`, `ReplayMode`, `#[MemoryReplay]` in the public surface matrix
- [laravel-swarm-memory-vector](https://github.com/builtbyberry/laravel-swarm-memory-vector) — vector-backed recall companion package (v0.1.0+, requires laravel-swarm v0.9.0+)
