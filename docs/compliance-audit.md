# Compliance & Audit

This is the runbook for running Laravel Swarm in regulated workloads. It shows
how five subsystems — the **memory capture policy**, the **propagation policy**,
frozen **snapshots**, **retention** enforcement, and the **audit-packet export**
— combine to satisfy the obligations auditors actually ask about: redact PII at
the boundary, prove what an agent saw, replay a run deterministically, age data
out on a committed schedule, and hand over a complete subject-access export.

It is also the answer to the question a `laravel/ai` user asks first: *what does
Swarm add that we don't already have?* A bare LLM call leaves no durable record
of what context the model saw, no boundary to keep regulated data out of that
context, and nothing to hand an auditor. Swarm makes each of those a first-class,
testable control — summarized here, with the implementing detail in
[docs/memory.md](memory.md).

## How the controls fit together

Each control answers one compliance question. They compose: redaction at write
keeps PII out of the snapshot, the snapshot bounds what an agent can see, the
inspector reads the snapshot back verbatim, retention ages the rest out, and the
export packages it all for handoff.

| Requirement | Control | Mechanism | Reference |
| --- | --- | --- | --- |
| PII never lands (data minimization) | Memory capture policy | `MemoryCapturePolicy` decides `Full` / `Redact` / `Skip` at the write boundary (#121) | [below](#memory-capture-policy) |
| Bound what an agent is exposed to | Propagation policy + snapshot | `MemoryPropagationPolicy` filters the view; the frozen `MemorySnapshot` records exactly that view (#119) | [below](#what-an-agent-can-see) |
| Prove what an agent saw / deterministic replay | Snapshot inspection | `swarm:memory:inspect` reads the frozen snapshot verbatim; durable retries replay from it (#122, #118/#127) | [below](#audit-replay-and-inspection) |
| Age regulated data out on schedule | Retention | `swarm:memory:purge` enforces per-scope windows; `MemoryPurged` event is the proof (#124) | [below](#memory-retention) |
| Data-subject access / portability export | Audit packet | `swarm:memory:dump` produces a self-describing, read-only export (#123) | [below](#exporting-an-audit-packet) |

The two write-time controls share a single design rule: a policy is consulted
with only the **address** of the data (scope and key), never its value, so the
policy code itself can never become a leak path and can never couple to payload
shape.

> **Run-history / evidence capture is a sibling control.** `MemoryCapturePolicy`
> governs the **memory** write boundary; the audit `CapturePolicy` (inputs,
> outputs, artifacts, active context) governs what lands in **run history,
> lifecycle/stream events, and audit evidence**. Both return the same
> `Full` / `Redact` / `Skip` decision. As of **v0.12.0**, a `Skip` from the audit
> `CapturePolicy` is true omission on the **evidence** surfaces — the field is
> absent from persisted/emitted payloads and `NULL` on the nullable evidence
> columns, and a failure under `Skip` drops `error.message` while keeping
> `error.class`. The operational active-context store (`swarm_contexts.input`)
> retains the encrypted input for durable resume and is never nulled by `Skip`.
> The default boolean policy never returns `Skip`, so `swarm.capture.*=false`
> installs still record `[redacted]`.
> See the [audit evidence contract](audit-evidence-contract.md#capture-policy).
>
> **Streamed multi-step resume checkpoints are operational state too.** The
> `swarm_stream_step_checkpoints` table (#202) records a completed non-final
> streamed step's raw output so an abandoned `stream()` run can resume without
> re-executing it. Like `swarm_contexts.input`, it is operational resume state,
> **not** an evidence surface: the output is stored untouched (not gated by
> `swarm.capture.*`), **encrypted at rest** when `encrypt_at_rest` is enabled
> (the default under the database driver), never audited or emitted, and pruned
> with the run (the `swarm_run_histories` FK cascade, plus early-prune under
> `swarm:memory:purge`). A capture-sensitive deployment that must not retain
> non-final outputs at all should set `swarm.memory.replay_mode=fresh_execution`,
> which disables checkpoint storage entirely (steps then re-execute on resume).

## Memory capture policy

Retention ([below](#memory-retention)) ages PII *out* after it lands. The
**capture policy** is the complementary control that keeps PII from landing in
the first place — the strongest form of data minimization (GDPR Art. 5(1)(c);
HIPAA minimum-necessary, §164.502(b)). `MemoryCapturePolicy` is consulted at the
write boundary and decides, per `(scope, key)`, whether each memory entry is
written as-is (`Full`), structurally redacted (`Redact`), or dropped entirely
(`Skip`).

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
persisted unchanged — do not place PII in memory metadata or keys. This is a
deliberate boundary, not an oversight: metadata and keys drive functional
behavior (indexing, filtering, routing), so structurally redacting them would
break lookups and ordering — and, as with the audit `CapturePolicy`, the policy
never receives the value, so it cannot couple to payload shape. Keep PII in the
entry value, where the policy can redact it; the value is also what flows into
the propagation view, frozen snapshots, and `swarm:memory:dump`, so redacting it
covers every downstream surface at once.

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
[docs/memory.md → Capture policy](memory.md#capture-policy-write-time-redaction),
and the two regulated-app policies in
[Worked configurations](#worked-configurations) below.

Together the two controls form the memory compliance story: **capture policy**
keeps disallowed data out at write, **retention** ages permitted data out on a
schedule.

## What an agent can see

When an agent uses the `RemembersRunContext` trait
([docs/memory.md](memory.md#reading-run-memory-inside-an-agent-with-remembersruncontext)),
its `messages()` renders the run's memory as conversation for the model. That
rendering is **not** a separate data path: it is built through the same
`AgentVisibleMemoryView` the runners use to freeze each snapshot, so it inherits
both compliance controls above. The propagation policy filters which entries are
presented, and the capture policy has already redacted or skipped values at the
write boundary — reads return the already-redacted value.

The practical guarantee for auditors: **what the trait feeds the model can never
exceed what `swarm:memory:inspect <run-id>` displays for that step.** The frozen
snapshot is the upper bound on agent-visible memory, and the inspector shows the
snapshot verbatim. There is no back channel that bypasses the policy or the
redaction sentinel.

## Audit replay and inspection

The frozen `MemorySnapshot` is the package's audit primitive: a point-in-time,
read-only record of exactly the memory view an agent was given immediately
before it ran. `swarm:memory:inspect` reads that record back without
interpretation, and the durable runtime *replays* from the same record — so the
artifact you inspect and the state a retry reconstructs are one and the same.

### Inspect a frozen snapshot

```bash
# List every step recorded for a run.
php artisan swarm:memory:inspect 9b2c0e7a-...

# Show the full snapshot for one step.
php artisan swarm:memory:inspect 9b2c0e7a-... --step=2

# Machine-readable, single scope, for an audit pipeline.
php artisan swarm:memory:inspect 9b2c0e7a-... --step=2 --format=json --scope=run
```

`--step=N` selects a step (omit to list all recorded steps); `--format=table|json`
controls output (default `table`); `--scope=run|conversation|agent|swarm` filters
the entries view. The command routes reads through the `SnapshotsMemory` contract,
so it works uniformly across whatever store the application binds; under the
`cache` persistence driver it surfaces a configuration hint rather than a
misleadingly empty result. Each successful read dispatches a `MemoryInspected`
event and emits a `command.memory.inspect` audit category recording the lookup
parameters and snapshot count — so operator access to frozen memory views is
itself auditable (failed reads do not dispatch).

### Replay determinism is the evidence

When a durable agent retries after a crash, `MemoryReplayCoordinator` swaps the
live store for a frozen, read-only view of the snapshot recorded at the original
invocation (`ReplayMode::FrozenView`, the default). The agent re-runs against the
exact `Run`-scoped state it saw before — regardless of any writes that happened
between the failed attempt and the retry. This is what makes a run *reproducible*
for an auditor: the inspector shows the snapshot, and a replay is guaranteed to
reconstruct from that same snapshot.

That guarantee is backed by a regression suite, not just a design claim. The
crash-resume replay-determinism tests (#118, `tests/Feature/Memory/ReplayDeterminismTest.php`)
assert byte-identical replay across every durable topology. The scope-isolation +
propagation suite (#127, `tests/Feature/Memory/ScopeIsolation/` and
`tests/Feature/Memory/Propagation/`) adds assertions that concurrent runs never
bleed across scopes and that each runner — sequential, parallel, hierarchical,
and the durable branch advancer — enforces its propagation policy under both the
default and a custom restrictive policy. (The default-policy cases for the live
sequential/parallel/hierarchical runners live alongside in
`tests/Feature/Memory/PropagationPolicyTest.php`; the `Propagation/` directory
adds the durable runner and the restrictive policy.) All are tagged into the `compliance`
Pest group; run them together as the release compliance gate with
`composer test:compliance` (they also run inside the standard `composer test`).
Together they are your replay evidence.

> **Step outputs are not in the snapshot.** With per-step output capture enabled
> (`swarm.memory.capture_step_output`, #163), each step's output is written to
> Run scope under the reserved key `swarm:step.{n}.output`. The default
> propagation policy **excludes** these reserved keys from the agent view and
> therefore from snapshots and `swarm:memory:inspect`. They are still in raw Run
> memory, so reach for [`swarm:memory:dump`](#exporting-an-audit-packet) — not
> `inspect` — when you need a turn-by-turn transcript as audit evidence.

## Memory retention

Laravel Swarm's memory subsystem (`SwarmMemory`) stores per-run,
per-conversation, per-agent, and per-swarm entries in the `swarm_memories`
table when the database driver is active. The `swarm:memory:purge` Artisan
command enforces per-scope retention windows so PII-bearing entries do not
outlive the windows committed to in your privacy policy or regulatory
filings (GDPR Art. 5(1)(e), HIPAA §164.530(j), SOC 2 CC6.5, FDA 21 CFR
Part 11 §11.10(c) for record retention). These citations map a control to the
expectation it helps satisfy; they are illustrative, not a certification. Part 11
in particular also expects the audit trail *itself* to be secure and
tamper-resistant (§11.10(e)) — Swarm's trail is append-style and event-sourced,
so meeting that clause requires the WORM / hash-chain layer described under
[Exporting an audit packet](#exporting-an-audit-packet): the package surfaces the
events, your infrastructure makes them immutable.

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
with `status=skipped`. This is the switch to flip for a **legal hold** — it
stops scheduled deletion without disabling the audit trail or requiring you to
edit retention windows under time pressure.

## Exporting an audit packet

`swarm:memory:dump` produces a self-contained, machine-readable export of a
run's (or conversation's) complete memory + snapshot trail — the artifact you
hand to an auditor, a regulator, or opposing counsel when the answer to "show me
everything this run remembered" cannot be "here's our database". It is strictly
read-only and never mutates memory. This satisfies subject-access / portability
obligations (GDPR Art. 15 access, Art. 20 portability; CCPA §1798.110 right to
know) without granting third-party DB access.

```bash
# Full audit packet for one run, snapshots embedded, written to a file.
php artisan swarm:memory:dump 9b2c0e7a-... --include-snapshots --output=/tmp/run-9b2c.json

# DSAR-style conversation export (see the run-expansion note below).
php artisan swarm:memory:dump 1f0a... --as=conversation --include-snapshots --output=/tmp/conv-1f0a.json

# Record why the extraction happened — captured in the audit trail.
php artisan swarm:memory:dump 9b2c0e7a-... --reason="DSAR #4821" --output=/tmp/dsar-4821.json
```

The export is a stable envelope (`schema_version: "1.0"`) — pin that version in
any downstream tooling. The `--output` file is created `0600` (owner-only)
before any payload is written. The full schema and NDJSON record shape are
documented in
[docs/memory.md → Exporting a full run](memory.md#exporting-a-full-run-with-swarmmemorydump).

**Self-describing for audit.** The envelope records `subject_type`/`subject_id`,
`generated_at`, the `include_snapshots` flag, entry/snapshot counts, and a
`scopes_included` field — so a packet states exactly what it contains and how it
was produced. A run id and a conversation id are both bare UUIDs; the command
resolves the subject by probe and **refuses** an id that matches both (pass
`--as=run|conversation`) rather than silently guessing, so an export is never
quietly about the wrong subject.

**Scope boundary — read `scopes_included`.** A run export covers only
`Run`-scoped memory; `Agent`- and `Swarm`-scoped entries key on an agent/swarm
id, not a run id, and are not included. The `scopes_included` field states this
explicitly (`["run"]` for a run subject). Before certifying a run export as a
complete GDPR Art. 15 / CCPA right-to-know response, confirm the subject's data
lives in `Run` scope — if it spans agent or swarm scope, that data is gathered
separately.

**Conversation exports and run expansion.** Swarm records no link between a run
and a conversation in v0.10 (the runtime exposes no conversation handle), so a
conversation export carries its Conversation-scoped entries and reports
`runs_expanded: false` unless your application binds a `ConversationRunResolver`
(see [docs/memory.md](memory.md#exporting-a-full-run-with-swarmmemorydump)).
This matters for DSAR completeness: read `runs_expanded` in the envelope before
certifying a conversation export as a complete subject-access response, and bind
a resolver mapping your conversation/run topology if a full conversation trail
is required.

**Audit evidence of the extraction itself.** Every successful dump dispatches a
`MemoryDumped` event and emits a `command.memory.dump` audit category recording
what left the system (subject, format, counts, output target), the requesting OS
user (`requested_by`), and an optional operator `--reason`. Subscribe an audit
listener (or rely on the audit outbox under database persistence) to keep a
positive record of every export — when it happened, what left, who ran it, and
why. (`requested_by` is resolved best-effort from the invoking environment —
`SUDO_USER`, then `USER`/`LOGNAME`, then the POSIX process owner — so an
interactive `artisan` run usually records the shell user. Under a queue worker or
php-fpm it is the daemon's user, not the person who triggered the work; for an
authoritative end-user identity, pass `--reason` and/or capture the app session
in your own listener, since an artisan command has no app session.)

By design, the audit category records **completed** exports only. A dump that
fails — an ambiguous run/conversation id, an unwritable or already-existing
`--output` path, or the cache driver with no snapshot store — emits no
`command.memory.dump` record; it surfaces a non-zero exit and an `ok: false`
error to the operator instead. This keeps the category unambiguous: a
`command.memory.dump` entry always means data actually left the system, never an
attempt. The same applies to `swarm:memory:purge`, which audits real deletes,
dry runs, and `prevent_prune` suppressions, but treats a misconfigured store
(cache driver, missing table) as a console-surfaced setup error rather than an
audit event. If your posture requires logging *attempted* extractions or purges,
wrap the command or capture the console invocation upstream.

> Encryption of the dump output is out of scope for the command — wrap the
> `--output` file (or the piped stdout) in your own encryption-at-rest /
> transport controls when the packet contains regulated data.

## Worked configurations

Two illustrative postures. They are starting points, not certified
configurations — your retention windows and redaction rules must come from your
own legal and compliance review. Neither covers an industry framework end to
end; each shows how the Swarm controls map onto a familiar regulatory shape.

### Healthcare-style app (HIPAA-aware)

The posture: minimize PHI aggressively, keep run memory short-lived, and
preserve the audit trail under legal hold. The defining choice is to **deny by
default** — every key is redacted unless it is explicitly allow-listed as
non-PHI, so a sensitive key nobody anticipated can never leak in full:

```php
<?php

namespace App\Swarm\Compliance;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Support\Str;

final class PhiMinimizingCapturePolicy implements MemoryCapturePolicy
{
    /** Keys that must never be persisted at all, not even redacted. */
    private const SKIP = ['ssn', 'insurance*'];

    /** The only keys allowed to persist in full — everything else is redacted. */
    private const ALLOW_FULL = ['run.status', 'step.*.outcome', 'audit.*'];

    public function memory(
        MemoryScope $scope,
        string $key,
        ?RunContext $context = null,
        ?Actor $actor = null,
    ): CaptureDecision {
        if (Str::is(self::SKIP, $key)) {
            return CaptureDecision::Skip;
        }

        // Deny by default: anything not explicitly allow-listed is redacted.
        return Str::is(self::ALLOW_FULL, $key)
            ? CaptureDecision::Full
            : CaptureDecision::Redact;
    }
}
```

> **Match the patterns to your own key scheme.** `Str::is` patterns are globs
> anchored to the whole key, with `*` as the only wildcard — they are **not**
> regular expressions, and `.` is a literal dot. The most common footgun is
> assuming dot-style patterns cover snake_case keys: `patient.*` matches
> `patient.name` but **not** `patient_name`. Reach for `patient*` (no dot) to
> cover both. The deny-by-default shape above is what makes this safe — a
> mismatched allow-list pattern fails toward *more* redaction, never less.

| Pattern | Matches | Does **not** match |
| --- | --- | --- |
| `patient.*` | `patient.name`, `patient.dob` | `patient_name`, `patient`, `patientId` |
| `patient*` | `patient.name`, `patient_name`, `patientId` | `the_patient` |
| `*patient*` | `patient.name`, `the_patient_id` | `note` |
| `mrn` | `mrn` (exact only) | `mrn.value`, `patient_mrn` |

```php
// config/swarm.php
'memory' => [
    // Resolved via the same env key the package default uses, so the binding
    // stays overridable per-environment.
    'capture_policy' => env(
        'SWARM_MEMORY_CAPTURE_POLICY',
        App\Swarm\Compliance\PhiMinimizingCapturePolicy::class,
    ),

    'retention' => [
        'days' => [
            'run' => 30,          // short-lived working memory
            'conversation' => 90, // care-episode context
            'agent' => null,      // no PHI in agent scope by policy
            'swarm' => null,
        ],
        'prune_snapshots' => true, // snapshots age out with their memory
    ],
],
```

```dotenv
# Stop scheduled deletion during an investigation or litigation hold.
# MemoryPurged still fires (prevent_prune=true), so the audit trail is intact.
SWARM_PREVENT_PRUNE=true
```

Why it satisfies the obligation: PHI is minimized at the boundary under the
minimum-necessary standard (§164.502(b)), the deny-by-default policy redacts
unanticipated keys rather than storing them in full, and run memory ages out on
the schedule your privacy policy commits to. (HIPAA sets no fixed PHI-deletion
clock — minimum-necessary and applicable state law drive the window, not
§164.530(j), which is a six-year retention floor for *required documentation*,
not a PHI-aging mandate.) The legal-hold switch suspends deletion without
blinding the audit pipeline.

### Finance-style app (SOX-aware)

The posture is the inverse of healthcare on retention: SOX §802 expects
financial-control records to be **retained** — auditors' workpapers for seven
years under 18 U.S.C. §1520, with most programs applying a comparable horizon to
the records those audits rest on. The regulatory expectation is a complete,
durable audit trail rather than minimization, so this policy is the deliberate
opposite of the healthcare one: it **allows by default** and redacts only named
PII fields, keeping the full transaction record intact. Memory is kept long (or
retention is disabled and governed by an external records system).

```php
<?php

namespace App\Swarm\Compliance;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Support\Str;

final class FinancialAuditCapturePolicy implements MemoryCapturePolicy
{
    // Customer PII redacted; the surrounding transaction record is kept.
    // Allow-by-default means a missed pattern leaks in full, so cover BOTH
    // dot.case and snake_case forms — see the pattern note in the healthcare
    // example above (`card.*` matches `card.number`, not `card_number`).
    private const REDACT = [
        'account.number', 'account_number',
        'tax_id',
        'card.*', 'card_*',
    ];

    public function memory(
        MemoryScope $scope,
        string $key,
        ?RunContext $context = null,
        ?Actor $actor = null,
    ): CaptureDecision {
        // Everything else — amounts, decisions, approvals — is the audit
        // record and persists in full.
        return Str::is(self::REDACT, $key)
            ? CaptureDecision::Redact
            : CaptureDecision::Full;
    }
}
```

```php
// config/swarm.php
'memory' => [
    'capture_policy' => env(
        'SWARM_MEMORY_CAPTURE_POLICY',
        App\Swarm\Compliance\FinancialAuditCapturePolicy::class,
    ),

    // Capture the full per-step decision trail automatically (#163).
    // Stored full-fidelity; manage volume via retention, not truncation.
    'capture_step_output' => true,

    'retention' => [
        'days' => [
            'run' => 2557,          // ~7 years (SOX §802)
            'conversation' => 2557,
            'agent' => 2557,
            'swarm' => null,        // governed externally
        ],
        'prune_snapshots' => true,
    ],
],
```

Why it satisfies the obligation: the decision trail is captured in full and
retained for the statutory window, embedded customer PII is redacted without
breaking the record, every export and purge is itself logged
(`command.memory.dump` / `command.memory.purge`), and replay determinism (#118)
lets an auditor reconstruct any run exactly as it executed.

> Swarm provides an **append-style, event-sourced** audit trail — each write,
> redaction, purge, and export emits an event and an audit category — **not**
> cryptographic tamper-evidence. If your controls require WORM storage or
> hash-chained records, layer that on the sink your audit listener writes to;
> the package surfaces the events, your infrastructure makes them immutable.

## Audit packet checklist

When an auditor asks for evidence about a run, the complete packet is four
artifacts. Each is produced by a command or config already covered above.

- [ ] **Snapshot trail** — the frozen, per-step memory views.
  `php artisan swarm:memory:dump <run-id> --include-snapshots --output=…`
  (use `--reason=` to record why), plus
  `swarm:memory:inspect <run-id> --step=N` for spot checks.
- [ ] **Capture-policy configuration** — the `MemoryCapturePolicy`
  implementation and its binding (`swarm.memory.capture_policy`), proving which
  fields were redacted or skipped at write, backed by the `MemoryRedacted` /
  `MemoryWriteSkipped` events your audit listener recorded.
- [ ] **Retention proof** — the configured `swarm.memory.retention.days`
  windows and the `MemoryPurged` events (with `criteria.dry_run === false`)
  showing the schedule was enforced; or, under legal hold, the
  `criteria.prevent_prune === true` records showing deletion was suspended.
- [ ] **Replay evidence** — the passing crash-resume replay-determinism suite
  (#118) plus the scope-isolation/propagation suite (#127,
  `tests/Feature/Memory/ScopeIsolation/` and `tests/Feature/Memory/Propagation/`),
  runnable together as the `compliance` Pest group via `composer test:compliance`
  — demonstrating that the snapshot trail reconstructs the run deterministically
  and that scopes never cross-contaminated.

## Further reading

- [Swarm Memory](memory.md) — the full memory subsystem: scopes, read/write,
  lifecycle events, snapshots, replay, and the `RunContext` bridge.
- [Audit Evidence](audit-evidence-contract.md) — the package-wide capture and
  audit-category contract that the memory events plug into.
- [Operator Runbook: Audit Outbox Triage](operator-runbook-audit-outbox.md) —
  keeping the durable audit pipeline healthy.
- [Configuration](configuration.md) — every `swarm.memory.*` key in one place.
