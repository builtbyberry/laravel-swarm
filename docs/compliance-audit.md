# Compliance & Audit

> **Status:** stub. The full compliance guide is owned by
> [#126 — compliance & audit guide](https://github.com/builtbyberry/laravel-swarm/issues/126)
> and will land later in v0.10.0. This page exists today to host the memory
> retention and capture-policy sections so operators have a stable doc anchor.

## Memory capture policy

Retention (below) ages PII *out* after it lands. The **capture policy** is the
complementary control that keeps PII from landing in the first place — the
strongest form of data minimization (GDPR Art. 5(1)(c); HIPAA minimum-necessary,
§164.502(b)). `MemoryCapturePolicy` is consulted at the write boundary and
decides, per `(scope, key)`, whether each memory entry is written as-is
(`Full`), structurally redacted (`Redact`), or dropped entirely (`Skip`).

Redaction is enforced by the `RedactingMemoryStore` decorator that wraps the
memory driver (via `$app->extend(MemoryStore::class, …)`), so it is the single
chokepoint every write passes through — including a custom or companion store a
deployment binds itself (bind it, don't `Container::instance()` it). Critically,
the agent-visible propagation view and the frozen `MemorySnapshot` read back
through that same store — so PII redacted at write **never reaches a snapshot**,
and the audit-replay record is clean by construction rather than by a separate
scrubbing pass. A policy never sees the value it is deciding on (only the scope
and key), so the policy code itself cannot become a leak path.

**Scope.** Redaction covers the entry **value** only. The entry's `metadata`
(functional annotations such as `source`/`usage`) and the entry **key** are
persisted unchanged — do not place PII in memory metadata or keys.

**Audit evidence.** Each capture decision is observable: a `Redact` write
dispatches a `MemoryRedacted` event and a `Skip` dispatches `MemoryWriteSkipped`
(both carry only `scope`/`scopeId`/`key`, never the value). Subscribe an audit
listener to these to produce a positive record that redaction/drop occurred —
the answer to "prove the policy acted" — rather than inferring it from absent
data.

The default preserves pre-v0.10 behavior (every write is `Full`, and no
`MemoryRedacted`/`MemoryWriteSkipped` events fire). Opt in by binding a policy
via `swarm.memory.capture_policy` (`SWARM_MEMORY_CAPTURE_POLICY`) or the
container. See the worked example and `Redact`/`Skip` semantics in
[docs/memory.md → Capture policy](memory.md#capture-policy-write-time-redaction).

Together the two controls form the memory compliance story: **capture policy**
keeps disallowed data out at write, **retention** ages permitted data out on a
schedule.

## Memory retention

Laravel Swarm's memory subsystem (`SwarmMemory`) stores per-run,
per-conversation, per-agent, and per-swarm entries in the `swarm_memories`
table when the database driver is active. The `swarm:memory:purge` Artisan
command enforces per-scope retention windows so PII-bearing entries do not
outlive the windows committed to in your privacy policy or regulatory
filings (GDPR Art. 5(1)(e), HIPAA §164.530(j), SOC 2 CC6.5, FDA 21 CFR
Part 11 §11.10(c) for record retention).

### Configure per-scope windows

Set retention windows under `swarm.memory.retention.days` in
`config/swarm.php` (or via environment variables):

```php
'memory' => [
    'retention' => [
        'days' => [
            'run' => env('SWARM_MEMORY_RETENTION_RUN_DAYS', 30),
            'conversation' => env('SWARM_MEMORY_RETENTION_CONVERSATION_DAYS', 90),
            'agent' => env('SWARM_MEMORY_RETENTION_AGENT_DAYS', 365),
            'swarm' => env('SWARM_MEMORY_RETENTION_SWARM_DAYS'),
        ],
        'prune_snapshots' => true,
    ],
],
```

`null` disables enforcement for that scope so an unconfigured installation
never loses data without an explicit policy decision.

### Schedule the command

Add the command to your scheduler — see
[advanced setup](./advanced-setup.md#wire-durable-execution-manual-equivalent-of-swarminstalldurable):

```php
Schedule::command('swarm:memory:purge')->daily();
```

### Operator-driven runs

- `php artisan swarm:memory:purge --dry-run` — report counts without
  deleting anything. Useful before tightening a window in production.
- `php artisan swarm:memory:purge --scope=run` — limit to a single scope
  (e.g., to enforce a tight Run-scope window without touching agent state).
- `php artisan swarm:memory:purge --keep-snapshots` — skip the
  `swarm_memory_snapshots` cascade for Run-scoped purges. The cascade is on
  by default so replay snapshots do not outlive their memory.

The snapshot cascade only reaches snapshots whose run wrote a Run-scoped
memory row. Snapshots for a run that never wrote Run-scoped memory are owned
by run-history retention instead: `swarm_memory_snapshots.run_id` has a
`cascadeOnDelete` foreign key to `swarm_run_histories`, so they are removed
when `swarm:prune` ages out the parent run. In short, `swarm:memory:purge`
removes snapshots *early* when their memory ages out first; `swarm:prune` is
the backstop that guarantees no snapshot outlives its run history.

### Audit evidence

Every run dispatches a `MemoryPurged` event with per-scope counts and the
criteria the operator ran with (retention windows, scope filter, snapshot
flag, dry-run flag, prevent-prune flag, ISO-8601 cutoffs per scope).
Listeners that record audit evidence should filter on
`criteria.dry_run === false` to avoid treating preview runs as deletion
events, and inspect `criteria.prevent_prune` to tell a compliance-suppressed
run apart from a genuine zero-delete run (both report zero counts). The
command also emits a `command.memory.purge` audit category via the package
audit dispatcher so configured sinks (and the audit outbox, when database
persistence is active) capture the same payload.

### Prevent-prune escape hatch

`swarm:memory:purge` honors `swarm.retention.prevent_prune` the same way
`swarm:prune` does. With `SWARM_PREVENT_PRUNE=true`, destructive deletes are
suppressed but the run stays visible to your audit pipeline: the
`MemoryPurged` event still dispatches (with `criteria.prevent_prune = true`
and zero counts), and the `command.memory.purge` audit category is emitted
with `status=skipped`.
