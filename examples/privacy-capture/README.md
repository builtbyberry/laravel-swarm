# Privacy Capture

Shows how to reduce persisted and emitted prompt/output data for sensitive
workflows.

Use this pattern for support, compliance, document review, or customer-data
workflows where logs and history should not contain raw prompts or outputs.

This example teaches:

- capture flags affect events and persisted inspection data;
- disabled capture uses `[redacted]` instead of changing payload shape;
- metadata is not redacted and should not contain secrets.

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

$response = ComplianceReviewSwarm::make()->run([
    'document_id' => 1234,
    'review_goal' => 'summarize renewal risk',
]);

// The caller still receives the raw response output.
$response->output;
```

See `docs/persistence-and-history.md` for the full persistence and redaction
contract.
