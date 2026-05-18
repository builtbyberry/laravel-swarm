# APP_KEY Rotation

Laravel Swarm seals selected sensitive string columns with Laravel's encrypter
when database persistence is active and `swarm.persistence.encrypt_at_rest` is
`true` (the default). Sealing uses your application's `APP_KEY`. Rotating that
key without a re-encryption plan leaves the existing sealed rows unreadable.

This document covers the runbook: what is affected, what is not, how to
re-encrypt live operational rows, and how rotation interacts with retention.

## The Asymmetry

Two classes of data flow through Laravel Swarm. They react to `APP_KEY`
rotation in different ways:

- **Operational rows** stored in `swarm_*` database tables (context input,
  run history step I/O, durable branch input and output, hierarchical node
  outputs, child durable run outputs). When `encrypt_at_rest` is on, the
  sealed string columns are prefixed `sw0:` and decrypt with the active key.
  **Rotating `APP_KEY` without re-encrypting these rows makes the sealed
  fields unreadable.**
- **Audit evidence payloads** emitted through `SwarmAuditSink`. Evidence
  records never include raw prompt text or agent outputs and are not sealed
  by the package — they are owned by your application's audit target.
  **Rotating `APP_KEY` does not affect archived evidence.**

The same applies to telemetry payloads emitted through `SwarmTelemetrySink`:
they carry redacted or allowlisted fields only, and are not sealed by the
package.

## What Breaks After Rotation

When `APP_KEY` no longer matches the key used to write the sealed rows,
decrypt calls fail. The behavior is governed by
`swarm.persistence.decrypt_failure_policy`:

| Policy           | Behavior on the affected rows                                  |
|------------------|----------------------------------------------------------------|
| `null_with_log`  | Sealed field returns `null`; a warning is logged once per row. |
| `legacy`         | Sealed field returns the raw `sw0:` ciphertext string.         |
| `throw`          | Decrypt exception bubbles up. Reads fail loudly.               |

The default is `null_with_log`, so rotation without a plan does not crash
reads — it silently nulls out the sealed fields. Run history, durable
inspection, and operator commands continue to operate; the redacted output is
the only visible symptom. Audit evidence emissions and lifecycle events keep
their full structure because they do not depend on reading old sealed rows.

JSON columns (`data`, `metadata`, `artifacts`, persisted context payloads) are
not sealed by `encrypt_at_rest`. They survive rotation as-is. If your
application stores secrets inside those JSON payloads with its own encrypter,
plan its rotation independently.

## Recommended Strategy: Drain Then Rotate

The simplest, safest sequence for most applications is to **drain old sealed
rows out of the operational tables before you rotate**. The package already
treats operational tables as TTL-based runtime storage, not immutable archives,
so this aligns with the intended retention model.

1. Schedule (or run on demand) `swarm:prune` until rows older than your
   retention window are gone. The relevant categories are listed in
   [Maintenance](maintenance.md).
2. Drain the durable outbox so no in-flight runs are mid-checkpoint when you
   cut over:

   ```bash
   php artisan swarm:relay --drain-until-empty
   php artisan swarm:health --durable
   ```

3. Quiesce queue workers and let any active synchronous runs finish.
4. Rotate `APP_KEY` and deploy.
5. Resume workers. New runs will be sealed with the new key.

This avoids re-encryption entirely. The cost is the wait for the retention
window to clear.

## Re-Encryption Strategy For Live Rows

Some applications cannot wait for retention to drain — long-running durable
runs, active waits, or longer retention windows make a flush impractical. In
that case re-encrypt the sealed columns in place during a maintenance window:

1. Take a backup of the swarm tables.
2. Set the application to read-only (or block traffic that mutates swarm
   state). Stop queue workers. Stop the scheduler so `swarm:relay` and
   `swarm:recover` do not run mid-rotation.
3. Add the new key to your environment but keep the old key available to
   application code — for example by writing a small script that holds the
   old `Encrypter` instance in memory.
4. For each sealed column (see the schema reference in [Persistence And
   History](persistence-and-history.md)), iterate rows where the column starts
   with `sw0:`, decrypt with the old key, encrypt with the new key, and write
   the re-prefixed value back. Process in bounded batches and commit each
   batch.
5. After every sealed column is rewritten, promote the new key to `APP_KEY`
   and deploy.
6. Resume workers and the scheduler.

Test the script against a staging copy of the production database before
running it for real. Keep `swarm.persistence.decrypt_failure_policy` at
`null_with_log` during the migration so any column the script misses surfaces
in logs rather than crashing reads.

## Interaction With Retention Windows

Retention is configured by `swarm.retention.*` (see
[Configuration](configuration.md)) and enforced by `swarm:prune`. Rotation
planning should account for the longest retention window across all swarm
table categories:

- **Run history** keeps step I/O for the configured retention.
- **Durable runtime** keeps cursors and branch records until the run finishes
  and retention expires.
- **Persisted stream replay** keeps stream events for its own retention.

A drain-then-rotate plan is only as fast as the longest active retention. If
you keep durable run history for 90 days and a durable run is currently
waiting on a signal that is 60 days old, the row will not prune until it
terminates and then ages out. Either lower the relevant retention temporarily,
finish or cancel the long-lived run, or use the in-place re-encryption path
above.

## Audit Evidence Is Not Re-Sealed

Archived evidence is owned by your application sink — an append-only table, an
object store bucket, a SIEM, or any equivalent. The package does not seal it,
does not re-seal it, and does not retain a handle to it. Treat evidence as a
separate encryption-at-rest concern (storage-level encryption on the
destination, KMS rotation on the bucket, etc.) and plan that lifecycle
independently of `APP_KEY`.

The [Audit Evidence Contract](audit-evidence-contract.md) production checklist
already calls this out:

> Rotate `APP_KEY` in coordination with your encryption-at-rest plan for
> database-persisted operational rows; archived evidence payloads are not
> affected by key rotation.

## Related Reading

- [Persistence And History](persistence-and-history.md) — column-level
  sealing scope and the `sw0:` prefix.
- [Configuration](configuration.md) — `swarm.persistence.encrypt_at_rest`,
  `swarm.persistence.decrypt_failure_policy`, and retention keys.
- [Maintenance](maintenance.md) — `swarm:prune`, `swarm:relay`, and
  `swarm:recover` reference.
- [Audit Evidence Contract](audit-evidence-contract.md) — what evidence
  contains and why it is unaffected by rotation.
