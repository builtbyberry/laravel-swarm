# Operator Visibility Improvements

Work items derived from Rimsys CTO review. Covers operator surface tightening:
health checks, outbox observability, metadata governance, and docs gaps.

---

## Plan 1 — Graduated Outbox Health + Configurable Threshold

**Problem:** `runOutboxStalenessCheck` gives a binary ok/warning. The threshold is `2 × reservation_timeout_seconds` hardcoded in the command body. Operators cannot distinguish an empty-and-fine outbox from a growing backlog.

**Files:**
- `config/swarm.php` — add under `durable.relay`:
  ```php
  'stale_warning_threshold_seconds' => (int) env('SWARM_DURABLE_RELAY_STALE_WARNING_THRESHOLD_SECONDS', 0),
  // 0 = use 2 × reservation_timeout_seconds (backwards-compatible default)
  ```
- `src/Commands/SwarmHealthCommand.php` — rework `runOutboxStalenessCheck`:

  Replace the single stale count with three queries:
  1. `$totalPending` — all unclaimed rows (unreserved or with expired reservation), regardless of age.
  2. `$agingCount` — rows where `created_at < $warningThreshold` AND unclaimed.
  3. `$claimedCount` — rows where `reserved_at IS NOT NULL AND reserved_at > $staleThreshold`. Actively in-flight.

  Map to three output states:
  - `$totalPending === 0` → `ok`, "no pending rows"
  - `$totalPending > 0 && $agingCount === 0` → `ok`, "N pending rows, relay appears active"
  - `$agingCount > 0` → `warning`, "N rows aging past {threshold}s — is swarm:relay scheduled?"

  Warning threshold resolution: read `stale_warning_threshold_seconds` from config; if zero, fall back to `2 × reservation_timeout_seconds`.

**Tests:** Add cases for each branch. Confirm JSON output includes the correct detail string per state.

**Docs:** Update the `swarm:health --durable` description in `docs/maintenance.md` to describe the three states.

---

## Plan 2 — "Relay Required" Indicator in Health `--durable`

**Problem:** When `--durable` is passed, nothing in the output tells the operator that `swarm:relay` must be scheduled. A new operator gets a passing health check with no scheduling reminder.

**Files:**
- `src/Commands/SwarmHealthCommand.php` — prepend a static informational row at the top of the `--durable` block:
  ```php
  [
      'component' => 'Relay scheduling',
      'driver'    => 'n/a',
      'store'     => 'n/a',
      'status'    => 'note',
      'details'   => "swarm:relay must run every minute for durable execution to advance — Schedule::command('swarm:relay')->everyMinute()",
  ]
  ```
  `note` is informational only. The exit-code logic only gates on `failed`, so no change needed there.

**Tests:** Assert the `note` row appears in `--durable` output and does not cause a non-zero exit.

---

## Plan 3 — `claimed`/`reclaimed` in `DrainResult` + Audit Payload

**Problem:** The audit sink receives `dispatched_count`, `skipped_count`, and `failed_count` but not how many rows were claimed in phase 1 or how many were reclaimed (previously reserved but expired). These are the signals that tell operators the relay is falling behind before rows age to warning.

**Files:**
- `src/Responses/DrainResult.php` — add two fields:
  ```php
  public function __construct(
      public readonly int $dispatched,
      public readonly int $skipped,
      public readonly int $failed   = 0,
      public readonly int $claimed  = 0,   // rows claimed in phase 1
      public readonly int $reclaimed = 0,  // of those, previously reserved but expired
  ) {}
  ```
  `total()` stays unchanged.

- `src/Persistence/DatabaseDurableOutbox.php` — after phase 1 claims rows:
  - `$claimed = $entries->count()` (already available)
  - `$reclaimed = $entries->filter(fn($e) => $e->reserved_at !== null)->count()` — entries that had a non-null `reserved_at` before we overwrote it. The field is already in the `get()` result; no extra query needed.
  - Pass both to `new DrainResult($dispatched, $skipped, $failed, $claimed, $reclaimed)`.

- `src/Commands/SwarmRelayCommand.php` — add to the audit payload:
  ```php
  'claimed_count'   => $totalClaimed,
  'reclaimed_count' => $totalReclaimed,
  ```
  Track running totals the same way `$totalDispatched` is tracked.

- `src/Contracts/DurableOutbox.php` — update the `drain()` return docblock.

**Tests:** Assert new fields. Add a case where a row with a stale `reserved_at` is re-claimed and verify `reclaimed = 1`.

---

## Plan 4 — Queue-Routing Mismatch Detection in Health `--durable`

**Problem:** A row with an unknown `queue_connection` is permanently invalid and silently skipped at drain time. There is no way to detect stranded rows before they accumulate without running a drain.

**Files:**
- `src/Commands/SwarmHealthCommand.php` — add `runQueueRoutingCheck()` called alongside the staleness check when `--durable` is passed:
  ```php
  $knownConnections = array_keys((array) $config->get('queue.connections', []));
  $unknownCount = $connection->table($outboxTable)
      ->whereNotNull('queue_connection')
      ->whereNotIn('queue_connection', $knownConnections)
      ->count();
  ```
  Return `ok` with "all known connections" if zero; `warning` with "N rows reference unknown queue_connection — these will be permanently skipped at drain" if non-zero.

  Component name: `"Outbox queue routing"`. Same `array{component, driver, store, status, details}` shape. If the outbox table doesn't exist, catch the exception and return `failed` with a migration message (same pattern as `runOutboxStalenessCheck`).

**Tests:** Seed a row with an unrecognised `queue_connection`, assert `warning`. Seed rows with only known connections, assert `ok`.

---

## Plan 5 — Metadata Size Cap Config Key

**Problem:** Docs warn against large metadata but nothing enforces it. The existing `limits` section already has `max_input_bytes`/`max_output_bytes` with the same overflow policy — metadata belongs here.

**Files:**
- `config/swarm.php` — add to `limits`:
  ```php
  'max_metadata_bytes' => env('SWARM_MAX_METADATA_BYTES'),
  // null = uncapped. Enforced when metadata is serialized at run start.
  // Uses the same swarm.limits.overflow policy as input/output limits.
  // Note: truncate is not supported for metadata (structured array); only fail applies.
  ```

- `src/Support/SwarmPayloadLimits.php` — add `checkMetadata()`:
  ```php
  public function checkMetadata(array $metadata): void
  {
      try {
          $payload = json_encode($metadata, JSON_THROW_ON_ERROR);
      } catch (JsonException $e) {
          throw new SwarmException('Swarm metadata must be plain data that can be encoded as JSON.', previous: $e);
      }
      $this->ensureWithinLimit($payload, 'metadata', $this->configuredBytes('max_metadata_bytes'));
  }
  ```

- `src/Runners/SwarmRunner.php` (line ~484) — call `$this->limits->checkMetadata($context->metadata)` alongside the existing `checkInput` call.

- `src/Runners/Durable/DurableSwarmStarter.php` (line ~41) — same addition alongside its `checkInput` call.

**Tests:** Mirror the existing input-limit tests. Confirm oversized metadata throws; `null` is a no-op.

**Docs:** One line in `docs/persistence-and-history.md` under the metadata warning, pointing to `swarm.limits.max_metadata_bytes`.

---

## Plan 6 — Worker Topology Docs

**Problem:** The docs show what to schedule but not how to structure workers. Operators copy the scheduling examples without understanding queue isolation, which causes durable work to compete with regular jobs.

**File:** `docs/maintenance.md` — add `### Recommended Queue Topology` under the existing `## Scheduling` section. Three patterns:

**Minimal** — single queue, development or low-volume non-durable:
```bash
php artisan queue:work --queue=default
```

**Durable sequential** — dedicated queue, separate worker pool:
```bash
# App workers
php artisan queue:work --queue=default --timeout=60

# Durable workers (timeout must exceed step_timeout + margin)
php artisan queue:work --queue=swarm-durable --timeout=120 --tries=3
```
Note: `retry_after` on the queue connection must exceed the worker timeout.

**Durable with parallel branches** — additional queue for branch jobs prevents branch steps from queueing behind sequential steps and avoids saturation deadlocks:
```bash
php artisan queue:work --queue=default --timeout=60
php artisan queue:work --queue=swarm-durable --timeout=120 --tries=3
php artisan queue:work --queue=swarm-branches --timeout=120 --tries=3
```
Point `SWARM_DURABLE_PARALLEL_QUEUE=swarm-branches`.

Each pattern: one-line "when to use" and a `retry_after` note. Keep the section under 60 lines.

---

## Plan 7 — Metadata Redaction Hook Docs

**Problem:** Docs warn not to put sensitive data in metadata but don't show how to enforce that through the existing sink contracts. The `metadata_allowlist` config and custom sink bindings are already the right tools; operators don't know to use them.

**File:** `docs/audit-evidence-contract.md` — add `## Metadata Governance` after the existing metadata_allowlist config reference. Three sub-topics:

1. **Allowlist approach** — show `SWARM_AUDIT_METADATA_ALLOWLIST` / `SWARM_OBSERVABILITY_METADATA_ALLOWLIST` with a concrete example: only `customer_id` and `workflow_type` pass through. Clarify that `metadata_keys` (the array of all key names) is always emitted regardless, so you know what keys existed without receiving values.

2. **Custom sink redaction** — minimal `SwarmAuditSink` implementation that transforms metadata values (e.g. hash a value rather than drop it) for cases where allowlist alone is not enough.

3. **Scope note** — these controls apply to sink/telemetry payloads only. They do not affect what is stored in `RunContext` at runtime or in the database with `capture.active_context` enabled. For storage-level redaction, point to capture defaults and `encrypt_at_rest`.

Keep the section under 50 lines of prose + code.

---

## Parallelism

| Agent | Plans | Files touched |
|---|---|---|
| A | 1, 2, 4 | `SwarmHealthCommand.php`, `config/swarm.php` |
| B | 3 | `DrainResult.php`, `DatabaseDurableOutbox.php`, `SwarmRelayCommand.php`, `DurableOutbox.php` |
| C | 5 | `SwarmPayloadLimits.php`, `SwarmRunner.php`, `DurableSwarmStarter.php`, `config/swarm.php` |
| D | 6, 7 | `docs/maintenance.md`, `docs/audit-evidence-contract.md` |

All four agents can run concurrently. Agent A and Agent C both touch `config/swarm.php` but write to different sections (`durable.relay` vs `limits`), so they will need a merge step before committing. Agents B and D are fully independent.
