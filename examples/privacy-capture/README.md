# Privacy Capture

Shows how to reduce persisted and emitted prompt/output data for sensitive
workflows.

Use this pattern for support, compliance, document review, or customer-data
workflows where logs and history should not contain raw prompts or outputs.

This example teaches:

- capture flags affect events and persisted inspection data;
- disabled capture uses `[redacted]` instead of changing payload shape;
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
