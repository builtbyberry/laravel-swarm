# Privacy Capture

Shows how to reduce persisted and emitted prompt/output data for sensitive
workflows.

Use this pattern for support, compliance, document review, or customer-data
workflows where logs and history should not contain raw prompts or outputs.

This example teaches:

- capture flags affect events and persisted inspection data;
- disabled boolean capture uses `[redacted]` instead of changing payload shape;
- a custom `CapturePolicy` returning `CaptureDecision::Skip` omits the field
  entirely (absent key / `NULL` column) as of v0.12.0;
- metadata is not redacted and should not contain secrets.

## Prerequisites

- Decide whether your application needs operational inspection, audit evidence,
  or both.
- Use database persistence when retained history must survive cache eviction.
- Set `SWARM_CAPTURE_ACTIVE_CONTEXT=true` for queued or durable swarms even when
  input and output capture stay disabled.

## Configuration

```bash
SWARM_CAPTURE_INPUTS=false
SWARM_CAPTURE_OUTPUTS=false
```

Or in `config/swarm.php`:

```php
'capture' => [
    'inputs' => false,
    'outputs' => false,
],
```

## Behavior

When input capture is disabled, Laravel Swarm keeps event and history payload
shapes stable but replaces captured input values with `[redacted]`.

When output capture is disabled, Laravel Swarm replaces captured output values
with `[redacted]` and skips automatic `agent_output` artifact persistence.

Returned `SwarmResponse` values and live agent handoffs remain raw in the
current PHP process. Capture settings control inspection surfaces, not runtime
execution.

Metadata is developer-supplied operational data and is not redacted by capture
flags. Do not place secrets in metadata.

## Usage

```php
use App\Ai\Swarms\ComplianceReviewSwarm;

$response = ComplianceReviewSwarm::make()->prompt([
    'document_id' => 1234,
    'review_goal' => 'summarize renewal risk',
]);

// The caller still receives the raw response output.
$response->output;
```

See `docs/persistence-and-history.md` for the full persistence and redaction
contract.

## Going Further With v0.4

The boolean capture flags above remain the simplest control. v0.4 adds three
extension points for regulated workloads that need richer attribution, per-event
capture decisions, or signed audit evidence.

### Bind The Acting User Before Dispatch

Resolve an `Actor` once at request entry so every emitted audit record carries
who initiated the run. The default `ActorResolver` reads `Context::get('swarm:actor')`
first, which means a single `Context::add(...)` call propagates the actor across
sync, queued, and durable workers.

```php
use Illuminate\Support\Facades\Context;

Context::add('swarm:actor', $request->user());

ComplianceReviewSwarm::make()->dispatchDurable([
    'document_id' => 1234,
]);
```

For regulated deployments, set `SWARM_AUDIT_ACTOR_REQUIRED=true` so runs without
a resolvable actor throw `MissingActorException` at dispatch entry rather than
emitting unattributed evidence.

### Custom Capture Policies

Bind `BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy` in the container when
booleans are too coarse — for example, when run-level evidence should remain
full but tool-call payloads must be omitted entirely.

```php
use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

class ComplianceCapturePolicy implements CapturePolicy
{
    public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return CaptureDecision::Full;
    }

    public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return CaptureDecision::Full;
    }

    public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return CaptureDecision::Skip;
    }

    public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return CaptureDecision::Redact;
    }
}
```

Policies never see the payload itself — decisions are made from the
`RunContext` and resolved `Actor` only. The default binding
(`BooleanCapturePolicy`) preserves today's behavior; bind your own to opt into
per-category decisions.

The three decisions differ in **shape**, not just content:

| Decision | Persisted / emitted shape |
| --- | --- |
| `Full` | The value, as-is. |
| `Redact` | Scalar values replaced with `[redacted]`; keys and structure preserved. |
| `Skip` | The field is **omitted entirely** (v0.12.0+) — the key is absent from history/events and the column is `NULL` (`swarm_run_steps.input`/`output`, `swarm_contexts.input`, `swarm_run_histories.output`). A failure under `Skip` drops `error.message` but keeps `error.class`. |

Use `Skip` (not `Redact`) when even a `[redacted]` placeholder would leak that
a field existed, or when downstream consumers must distinguish "deliberately
not captured" from "captured but masked". The boolean flags above can never
produce `Skip` — only an explicit policy can.

### Sign Audit Evidence

Bind `BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner` to add signature
fields to every record before it reaches the configured `SwarmAuditSink`. A
minimal HMAC signer:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;

class HmacAuditSigner implements SwarmAuditSigner
{
    public function __construct(private readonly string $secret) {}

    public function sign(string $category, array $payload): array
    {
        $canonical = json_encode($payload, JSON_THROW_ON_ERROR);

        return [
            ...$payload,
            'signature' => hash_hmac('sha256', $canonical, $this->secret),
            'signature_algorithm' => 'HMAC-SHA256',
            'signed_at' => now()->toIso8601String(),
        ];
    }
}
```

Implementations must not mutate or remove existing keys. See
`docs/audit-evidence-contract.md` for the full envelope contract, signing
scope guidance, and chain-signing patterns.

## v0.5 Audit Chain For Redacted Evidence

Privacy-sensitive workflows lean harder on the v0.5 audit chain than
non-regulated callers do. With redaction enabled, the audit evidence emitted
for each run is the **only** durable record of what happened — there is no raw
prompt or output to fall back on. So the behavior of the bound `SwarmAuditSink`
under failure becomes a privacy and compliance question, not just an operations
one.

In v0.5, `SWARM_AUDIT_FAILURE_POLICY` **defaults to `queue`**. That means:

- A sink that throws no longer silently drops the redacted evidence record.
- Instead, the failed record is persisted to the `swarm_audit_outbox` table
  for retry via `swarm:relay --type=audit`.
- When `swarm.persistence.encrypt_at_rest=true` (also the default on database
  persistence), the persisted `payload` and `last_error` columns are sealed
  with `SwarmPersistenceCipher` — the same encrypter-backed sealing used
  elsewhere in the package, scoped to `APP_KEY`.

For a privacy-capture deployment the recommended baseline is:

```bash
SWARM_PERSISTENCE_DRIVER=database
SWARM_CAPTURE_INPUTS=false
SWARM_CAPTURE_OUTPUTS=false

# v0.5 defaults — listed explicitly for the audit chain
SWARM_AUDIT_FAILURE_POLICY=queue
SWARM_ENCRYPT_AT_REST=true
SWARM_AUDIT_OUTBOX_DEAD_LETTER_RETENTION_DAYS=90
```

### Inspecting Redacted Payloads In The Outbox

When a sink failure persists a record, the redacted shape is preserved. Input
and output values are still `[redacted]`; the categories, run identifiers, and
metadata stay queryable for triage:

```bash
php artisan swarm:audit:status
```

```
  INFO  Audit outbox summary.

  ----------------------- -------
   Status                  Count
  ----------------------- -------
   pending (unclaimed)     2
   reserved                0
     stale (> 120s)        0
   dead_letter             0
  ----------------------- -------

  INFO  Top dead-letter categories.

  • no dead-letter rows
```

The summary tells operators **how much** redacted evidence is queued and
**where it is in the lifecycle**, without exposing the payloads themselves —
which is exactly the layer of indirection privacy-sensitive workloads want
between routine monitoring and forensic inspection.

### A Privacy Note On `swarm:audit:reconcile --show`

`swarm:audit:reconcile --show=<id>` **unseals** the encrypted-at-rest payload
and prints it to the terminal for human review:

```bash
php artisan swarm:audit:reconcile --show=42
```

In a privacy-capture deployment this command performs a **privileged
re-disclosure**. The payload itself is already redacted at the field level, so
input and output values remain `[redacted]`, but metadata, actor identity, and
correlation IDs become visible. Treat access to `swarm:audit:reconcile --show`
the same way you treat access to your application's audit-log viewer — log
the operator who ran it, restrict it to the on-call audit reviewer role, and
gate it behind your standard privileged-command controls.

`swarm:audit:reconcile --requeue` and `--dismiss` are themselves audited: each
sub-mode emits a `command.audit_reconcile` evidence record **before** the
outbox row is mutated. If the audit emit fails the row is left untouched, so
reconciliation can never silently erase redacted evidence.

For the full forensic loop — including the simulated sink-outage walkthrough,
recovery via the relay, and dead-letter triage — see
[`durable-compliance-review/README.md`](../durable-compliance-review/README.md#v05-audit-chain-walkthrough).
The operator decision tree for audit-outbox triage lives in
[`docs/operator-runbook-audit-outbox.md`](../../docs/operator-runbook-audit-outbox.md)
(GitHub issue #45).
