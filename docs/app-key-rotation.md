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
  sealed values are prefixed `sw0:` and decrypt with the configured encrypter.
  This includes designated fields nested inside otherwise unsealed JSON and
  package-owned cold replay archives; see the inventory below.
  **Rotating `APP_KEY` without re-encrypting these rows makes the sealed
  fields unreadable.**
- **Audit evidence payloads** emitted through `SwarmAuditSink`. Evidence
  records never include raw prompt text or agent outputs and are not sealed
  by the package — they are owned by your application's audit target.
  **Rotating `APP_KEY` does not affect these external audit-sink payloads.**
  This does not describe package-owned cold replay archives, which contain
  sealed operational data.

The same applies to telemetry payloads emitted through `SwarmTelemetrySink`:
they carry redacted or allowlisted fields only, and are not sealed by the
package.

## What Breaks After Rotation

When `APP_KEY` no longer matches the key used to write the sealed rows,
decrypt calls fail. Ordinary display reads using the cipher's `open()` method
follow `swarm.persistence.decrypt_failure_policy`:

| Policy           | Behavior on the affected rows                                  |
|------------------|----------------------------------------------------------------|
| `null_with_log`  | Sealed field returns `null`; a warning is logged once per row. |
| `legacy`         | Sealed field returns the raw `sw0:` ciphertext string.         |
| `throw`          | Decrypt exception bubbles up. Reads fail loudly.               |

The default is `null_with_log`, but not every reader follows that display
policy. Citation reads use display-safe decoding and return `unavailable` with
reason `decrypt_failed` when their key is missing, without returning ciphertext
or throwing under the `legacy` or `throw` policies. Operational checkpoint and
cold-snapshot readers use strict decryption; losing their key can prevent reuse
of retained work. Do not treat successful commands or an absence of log warnings
as proof that rotation preserved all evidence.

JSON containers are not encrypted wholesale. Designated nested values **are**
sealed, including persisted context input, legacy step I/O, and citation
envelopes. Preserve unrelated JSON fields while rotating these values. Arbitrary
`data`, `metadata`, or artifact content is not automatically sealed; any
application-owned encryption has its own rotation requirements.

## Citation And Replay Inventory

Resolve actual table names from `swarm.tables.*`; the names below are defaults.
Extend the existing sensitive-field inventory in [Persistence And
History](persistence-and-history.md) with these locations:

| Location | Value to re-encrypt |
| --- | --- |
| `swarm_run_histories`, `swarm_run_steps`, `swarm_durable_branches`, `swarm_durable_node_outputs`, `swarm_stream_step_checkpoints` | The direct `citation_evidence` column, when its value starts with `sw0:`. |
| `swarm_run_histories.steps` legacy inline JSON | Each step's `citation_evidence`, alongside its existing sealed I/O fields. |
| `swarm_stream_events.payload` JSON, including causal-log events | The nested `citation_evidence` string. The entire JSON column does not start with `sw0:`. |
| `swarm_cold_archives.payload`, where `archive_type = event` | The same nested `citation_evidence` copied from the hot event. |
| `swarm_cold_archives.payload`, where `archive_type = snapshot` | The existing whole sealed snapshot string; do not treat it as an event JSON object. |

Only transform values written in the package's sealing format. Leave null,
legacy plaintext, and unsealed capture data unchanged. Decode JSON containers,
replace only the designated encrypted values, and preserve event identity,
ordering, availability, and surrounding fields. Cold events and snapshots are
different row shapes, even though they share a table.

## Recommended Strategy: Drain Then Rotate

The simplest, safest sequence for most applications is to **drain old sealed
rows out of the operational tables before you rotate**, if your retention
policy permits deleting them. Pruning hot operational rows does not remove
every sealed value: cold replay archives have no automatic TTL pruning.

1. Schedule (or run on demand) `swarm:prune` until rows older than your
   retention window are gone. The relevant categories are listed in
   [Maintenance](maintenance.md).
2. Drain the durable outbox so no in-flight runs are mid-checkpoint when you
   cut over:

   ```bash
   php artisan swarm:relay --drain-until-empty
   php artisan swarm:health --durable
   ```

3. Block new swarm dispatches from application traffic and all other producers.
   Let already-running synchronous requests and worker jobs finish, then stop
   queue workers and the scheduler, including compaction. Keep new dispatches
   blocked through inventory verification and deployment with the new key.
4. Verify that no retained data still needs the old key, including active or
   unexpired operational rows, legacy inline steps, checkpoints, and cold
   archives. Apply your explicit cold retention policy; do not infer that cold
   data is gone from a successful `swarm:prune`. If data must remain, use the
   re-encryption strategy below instead. Then rotate `APP_KEY` and deploy.
5. Resume workers and the scheduler, then reopen dispatches. New runs will be
   sealed with the new key.

This avoids re-encryption only when the inventory confirms no retained value
requires the old key. Waiting for hot TTLs alone does not establish that.

## Re-Encryption Strategy For Live Rows

Some applications cannot wait for retention to drain — long-running durable
runs, active waits, or longer retention windows make a flush impractical. In
that case re-encrypt the sealed values in place during a maintenance window:

1. Take a backup of the swarm tables.
2. Block new swarm dispatches and other mutations from application traffic and
   all other producers. Let already-running synchronous requests and worker
   jobs finish before rewriting data, then stop queue workers and the
   scheduler so `swarm:relay`, `swarm:recover`, and `swarm:compact` cannot run
   mid-rotation. Keep writes blocked until the new-key deployment is complete.
3. Add the new key to your environment but keep the old key available to
   application code — for example by writing a small script that holds the
   old `Encrypter` instance in memory.
4. Process both whole sealed columns and the designated nested values in the
   inventory above, plus existing sealed context and step fields. Strip the
   `sw0:` prefix, decrypt using the old encrypter, encrypt the same plaintext
   using the new encrypter, and restore the prefix. Process bounded batches
   and commit each batch. Track progress so a restart distinguishes already
   rotated values from pending ones; never overwrite data on a decrypt error.
5. Before retiring the old key, verify retained data using an encrypter with
   **only the new key**. Compare representative final and per-step history,
   legacy inline steps, checkpoint evidence, hot replay, cold event replay,
   and cold snapshots with their pre-rotation values. Include every location
   actually present in your installation. Any new `unavailable` /
   `decrypt_failed` citation, missing source, changed attribution, or strict
   snapshot decryption failure means verification failed: keep writers stopped,
   preserve both keys and the backup, and correct the missed data.
6. Once the inventory and verification pass, promote the new key to `APP_KEY`,
   deploy, resume workers and the scheduler, and reopen dispatches. Retire the old key according
   to your backup and restore policy; old encrypted backups still need it.

Test the script against a staging copy of the production database before
running it for real. Log inspection is supplementary: verify decoded evidence
and its availability states directly. Do not recover missed citations by
rerunning providers or replace unreadable evidence with an empty source list.

## Interaction With Retention Windows

Retention is configured by `swarm.retention.*` (see
[Configuration](configuration.md)) and enforced by `swarm:prune`. Rotation
planning should account for the longest retention window across all swarm
table categories:

- **Run history** keeps step I/O for the configured retention.
- **Durable runtime** keeps cursors and branch records until the run finishes
  and retention expires.
- **Persisted stream replay** keeps stream events for its own retention.
- **Cold replay archives** are not enumerated by `swarm:prune` and have no
  `expires_at` column or history foreign key. They can outlive the hot/history
  rows. Retain and re-encrypt them, or delete them explicitly under your
  application's retention policy; see the [streaming retention
  horizon](operator-runbook-streaming-substrate.md#4-the-retention-horizon).

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
