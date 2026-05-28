# Compliance & Audit

> **Status:** stub. The full compliance guide is owned by
> [#126 — compliance & audit guide](https://github.com/builtbyberry/laravel-swarm/issues/126)
> and will land later in v0.10.0. This page exists today to host the memory
> retention section so `swarm:memory:purge` users have a stable doc anchor.

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

### Audit evidence

Every run dispatches a `MemoryPurged` event with per-scope counts and the
criteria the operator ran with (retention windows, scope filter, snapshot
flag, dry-run flag, ISO-8601 cutoffs per scope). Listeners that record
audit evidence should filter on `criteria.dry_run === false` to avoid
treating preview runs as deletion events. The command also emits a
`command.memory.purge` audit category via the package audit dispatcher so
configured sinks (and the audit outbox, when database persistence is
active) capture the same payload.

### Prevent-prune escape hatch

`swarm:memory:purge` honors `swarm.retention.prevent_prune` the same way
`swarm:prune` does. With `SWARM_PREVENT_PRUNE=true`, destructive deletes
are suppressed but the `MemoryPurged` event still dispatches with
`status=skipped`, so scheduled runs remain visible to your audit pipeline.
