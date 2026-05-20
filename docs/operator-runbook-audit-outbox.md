# Operator Runbook: Audit Outbox Triage

This is a 3 a.m. runbook. You were paged about the swarm audit outbox and need
to know what to do, in order, without first re-reading the reference docs.

For the design contract and full field reference, see
[Audit Evidence Contract](audit-evidence-contract.md) and
[Maintenance § Audit outbox](maintenance.md#audit-outbox). This page only
covers the operator decision trees.

The two operator commands referenced throughout — `swarm:audit:status` and
`swarm:audit:reconcile` — require the database persistence driver. On the
cache driver the audit outbox is not in use and these commands exit cleanly
with a clear message.

---

## 1. Page received: dead-letter row exists

You were paged by a Pulse alarm, log aggregator, or `swarm:health --audit`
failure. At least one row in `swarm_audit_outbox` has status `dead_letter` —
an audit event that was emitted, the sink rejected, the relay retried up to
`swarm.audit.outbox.max_attempts`, and the row was moved to dead-letter.
Without operator action, the evidence will not reach the sink.

### Step 1 — Read the shape of the problem

```bash
php artisan swarm:audit:status
```

What to look for:

- **`dead_letter` count** — how many rows you are triaging.
- **Top dead-letter categories** — if everything is `run.failed`, the sink
  probably handled `run.started` and broke later. If it is a single category
  like `command.audit_reconcile`, that narrows root cause.
- **Oldest dead-letter age** — anything older than the alarm window suggests
  this is not a one-off transient.
- **Retention** — confirm `dead_letter_retention_days` matches what you
  expect. The audit-safe default is `null` (indefinite).

### Step 2 — Inspect a representative row

Pick an ID from `swarm:audit:status` (or list with
`swarm:audit:reconcile --status=dead_letter`):

```bash
php artisan swarm:audit:reconcile --show=<id>
```

This unseals the payload and prints `last_error`. Read both. The error
string is the sink's verbatim rejection.

#### Audit trail of reads

Every `--show` invocation emits a `command.audit_reconcile` evidence
record with `action=show` so reads are counted in the audit chain. The
emit carries `target_id`, `target_category`, `target_run_id`,
`prior_attempts`, `target_created_at`, and `target_age_seconds` — but no
payload contents and no `reason` (reads do not require justification,
only accounting).

Shell access to `swarm:audit:reconcile --show` is operator-trusted.
Treat the command's reach the same way you treat database read access.
The audit emit accounts for individual reads but does not authorize
them — host-access controls are still where you gate who can run this
in the first place.

If the audit sink itself is unavailable when you run `--show`, the
command still prints the row (read-only, already in memory) but exits
non-zero with a clear warning so the broken audit chain is visible.
Rerun once the sink recovers.

### Step 3 — Decide

Match `last_error` (and, if needed, the sink's own logs) against the
following branches.

#### Branch A — Transient sink failure

Signal: `last_error` looks like a network timeout, 5xx from a downstream
API, transient DB error, or anything that would resolve on retry. The sink
itself is healthy now.

Action:

```bash
php artisan swarm:audit:reconcile --requeue=<id> --reason="sink restored"
```

The row goes back to `pending` with `attempts=0`; `last_error` is preserved
for forensics. The next `swarm:relay --type=audit` re-attempts emission.

#### Branch B — Permanent sink misconfiguration

Signal: `last_error` shows wrong endpoint, expired credential, schema
rejection, 4xx auth or validation. The sink will reject the next attempt
identically until the misconfiguration is fixed.

Action:

1. **Fix the sink first** — rotate the credential, update the endpoint,
   correct the schema, redeploy, whatever applies.
2. Verify with a single drain:

   ```bash
   php artisan swarm:relay --type=audit --limit=1
   ```

3. Requeue the rest:

   ```bash
   php artisan swarm:audit:reconcile --requeue=<id> --reason="endpoint rotated"
   ```

   For more than a handful of rows, script the loop using
   `swarm:audit:reconcile --status=dead_letter --json --limit=200` and
   feed the IDs into `--requeue=<id>` calls. Pass `--force` on each
   call to confirm requeue/dismiss; without it `--json` mutations exit
   with a `force_required` error envelope rather than silently
   aborting.

#### Branch C — Evidence is legitimately undeliverable and not worth retrying

Signal: the row reflects an event that should not have been emitted in the
first place (a test run, a known-orphaned import, a dev environment that
was misrouted into the regulated sink). Retrying will keep failing or pollute
the audit store.

Action:

```bash
php artisan swarm:audit:reconcile --dismiss=<id> --reason="dev-env test run; sink scope is prod only"
```

`--reason` is required. The dismissal emits a `command.audit_reconcile`
audit record (with `action`, `target_id`, `target_category`,
`target_run_id`, `prior_attempts`, `reason`, and `target_payload_digest`)
**before** deleting the row. If the audit emit fails outright (for example
under `failure_policy=halt`), the row is left untouched. Under the default
`queue` policy, the reconcile record is itself enqueued for retry —
evidence is preserved in the outbox even when the sink is unavailable, so
dismissal never silently erases evidence.

For regulated workloads, document the dismissal in your operations log
alongside the audit chain-of-custody record. "Consult your compliance
officer" applies for anything where you are not certain the row was
legitimately undeliverable.

#### Branch D — Unknown root cause

Signal: `last_error` is opaque, the category is unusual, or you cannot yet
distinguish A from B from C.

Action: **do nothing destructive.** The default
`dead_letter_retention_days=null` exists exactly for this case — rows wait
for an operator. Open an incident ticket, escalate, and only requeue or
dismiss once you can name which branch you are on.

---

## 2. Page received: stale pending rows

You were paged by `swarm:health` (or `swarm:health --audit`) reporting
staleness — pending rows whose `reserved_at` aged past 2× the relay
reservation timeout. This usually means the relay is not running, not that
the outbox is broken.

### Step 1 — Confirm the relay schedule

```bash
php artisan schedule:list
```

Look for `swarm:relay` running at the configured cadence (every minute is
the documented default). If it is not in the list, the schedule is
missing — see [Maintenance § Audit outbox](maintenance.md#audit-outbox)
for the schedule snippet.

### Step 2 — Confirm queue workers are running

The relay is a scheduled Artisan command, but downstream emission goes
through the same queue plumbing your application uses. Check:

- That the scheduler process itself is up (`php artisan schedule:work` /
  systemd unit / cron entry).
- That at least one worker is running for the queues durable and audit
  work lands on.

### Step 3 — Try a manual drain

```bash
php artisan swarm:audit:status                 # baseline counts
php artisan swarm:relay --type=audit           # drain only the audit lane
php artisan swarm:audit:status                 # confirm counts dropped
```

Expected: pending drops toward zero, `Replayed N audit record(s).` prints,
and no new `dead_letter` rows appear.

### Step 4 — Decide

- **Manual drain works, scheduled drain does not** → the scheduler or
  worker is the problem, not the outbox. Fix the scheduler, watch
  `swarm:audit:status` recover on the next tick.
- **Manual drain fails too** → look at the sink. Inspect application logs
  for the `Log::error` line emitted at dead-letter transition (it includes
  the row id and last error). Confirm the sink's queue connection,
  credentials, and downstream dependency. From here you are usually in
  Section 1, Branch B.

---

## 3. Sink is permanently broken

The worst case: the sink dependency is down indefinitely (a SIEM is being
migrated, an upstream archive bucket is offline for hours, a downstream
auth provider is in an extended outage). The relay will keep retrying, then
dead-letter, and you cannot fix the underlying sink right now.

### Rules

1. **Don't lose evidence.** Default `swarm.audit.failure_policy=queue` is
   the right setting. Let evidence accumulate in `swarm_audit_outbox`.
   That is what the table is for.
2. **Don't switch to `swallow` or `dead_letter` to silence the alarm.**
   `swallow` drops evidence permanently and silently. `dead_letter` skips
   the retry loop entirely. Both are the wrong move for regulated callers
   and they will not survive a post-incident review.
3. **Acceptable temporary mitigation** — set
   `SWARM_AUDIT_FAILURE_POLICY=log` **only if** your compliance posture
   permits a documented gap, and **only** for the duration of the outage.
   `log` keeps the run executing, writes the failure to the application
   logger, and does not persist evidence. The gap **must** be documented
   in your operations log with start time, scope, and authorizing party.
4. **Do not raise the alarm thresholds.** A growing outbox is the signal.
   Raising the threshold hides the problem.

### Procedure

1. Confirm the failure mode is the sink, not the outbox (Section 2).
2. If accumulating evidence is acceptable, leave `failure_policy=queue`
   and disable the noisy page on `swarm:audit:status` dead-letter growth
   for the documented outage window only.
3. When the sink is restored:

   ```bash
   php artisan swarm:relay --type=audit --drain-until-empty
   php artisan swarm:audit:status
   ```

   Expect pending to drain to zero. Dead-letter rows from the outage
   period need explicit requeue per Section 1 — they are not picked up by
   the relay automatically.
4. Restore `SWARM_AUDIT_FAILURE_POLICY=queue` if you changed it.

---

## 4. Retention decision tree

`swarm.audit.outbox.dead_letter_retention_days`
(`SWARM_AUDIT_OUTBOX_DEAD_LETTER_RETENTION_DAYS`) controls how long
dead-letter rows survive `swarm:prune`. The audit-safe default is `null`
(indefinite). Use this section to choose a setting per regulatory regime.

**This is not legal advice.** Retention windows are a compliance officer
decision; the entries below are starting points for that conversation, not
substitutes for one.

| Regime / context | Suggested starting point | Notes |
|---|---|---|
| FDA 21 CFR Part 11 (electronic records) | `null` (indefinite) until compliance signs off on a specific window | Part 11 retention is typically multi-year and tied to the underlying record's retention. The package default never deletes; that is the safest baseline. Consult your compliance officer. |
| SOC 2 | Match your in-scope retention policy (commonly 7 years for financial-control-relevant evidence) | The exact number depends on what your SOC 2 description commits to. Confirm with your auditor before setting a window. |
| HIPAA | Typically 6 years from creation or last effective date | The standard HIPAA documentation retention window. Consult your compliance officer; some states layer additional requirements. |
| No regulatory regime | Opt-in pruning is fine | Pick a window that balances disk space against your incident-investigation horizon. 90 days is a common starting point. |

Whatever you choose, set it explicitly and document it. A retention
decision that lives only in someone's head will be forgotten by the next
operator who reads this runbook.

---

## 5. Forensic reconstruction (forward marker for v0.7)

`swarm:trace <run_id>` is planned for v0.7
([#44](https://github.com/builtbyberry/laravel-swarm/issues/44)) and will
walk the full audit chain for a single run in one command. Until then,
manual reconstruction works as follows.

For a given `run_id`:

1. **Outbox (pending or dead-letter evidence for this run):**

   ```sql
   SELECT id, status, category, attempts, created_at, last_attempted_at
   FROM swarm_audit_outbox
   WHERE run_id = '<run_id>'
   ORDER BY created_at;
   ```

   For payloads, use `swarm:audit:reconcile --show=<id>` per row — it
   unseals the payload for you.

2. **Live audit log (what made it to the sink):** query your bound
   `SwarmAuditSink` target (your application's audit table, SIEM, or
   archive) by `run_id`. Cross-reference categories between the outbox
   and the live audit log to identify gaps.

3. **Durable runtime state:** for durable runs, join against
   `swarm_durable_runs` and `swarm_durable_run_state` on `run_id` for
   the route plan, current node, and lease history. See
   [Maintenance § High-volume dashboards](maintenance.md#high-volume-dashboards)
   for the operational query contract.

4. **Run history:** `swarm_run_histories` and its companion tables give
   per-step input/output and final status. `php artisan swarm:status` and
   `php artisan swarm:history` are the read-only entry points.

When `swarm:trace` ships, this section will be replaced with the
walkthrough.
