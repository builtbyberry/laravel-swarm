# Durable Webhook Ingress

Shows how to expose authenticated webhook routes for starting durable swarms and
sending durable signals.

Use this pattern when a trusted external system should start approved durable
workflows or continue a run that is waiting on a callback.

This example teaches:

- `SwarmWebhooks::routes()` registers only the swarms you allow;
- signed webhook auth fails closed by default;
- start routes return a `run_id`;
- signal routes release durable waits through `DurableSwarmManager::signal()`;
- `Idempotency-Key` protects retried deliveries.

## Prerequisites

- Laravel AI is configured in your application.
- `SWARM_PERSISTENCE_DRIVER=database`
- `SWARM_CAPTURE_ACTIVE_CONTEXT=true`
- `SWARM_WEBHOOKS_ENABLED=true`
- `SWARM_WEBHOOK_SECRET` is configured.
- Package migrations have run.
- A queue worker is running.
- `swarm:recover` is scheduled.

## Configuration

```env
SWARM_PERSISTENCE_DRIVER=database
SWARM_CAPTURE_ACTIVE_CONTEXT=true
SWARM_WEBHOOKS_ENABLED=true
SWARM_WEBHOOK_SECRET=base64-or-random-shared-secret
SWARM_WEBHOOK_AUTH_DRIVER=signed
```

## Routes

Register webhook routes from your route file or route service provider:

```php
use App\Ai\Swarms\ApprovalSwarm;
use BuiltByBerry\LaravelSwarm\Support\SwarmWebhooks;

SwarmWebhooks::routes([
    ApprovalSwarm::class,
]);
```

With the default prefix, this exposes:

```text
POST /swarm/webhooks/start/approval-swarm
POST /swarm/webhooks/signal/{runId}/{signal}
```

Laravel Swarm does not scan your application. Only registered swarm classes are
startable through webhook ingress.

## Start A Run

```bash
curl -X POST https://app.test/swarm/webhooks/start/approval-swarm \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: approval-start-100' \
  -H 'X-Swarm-Timestamp: 1767225600' \
  -H 'X-Swarm-Signature: <signature>' \
  -d '{"document_id":100}'
```

A successful start returns HTTP 202:

```json
{
  "run_id": "01J..."
}
```

Retries with the same idempotency key and request body return the original
`run_id` after completion.

## Send A Signal

```bash
curl -X POST https://app.test/swarm/webhooks/signal/01J.../approval_received \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: approval-signal-100' \
  -H 'X-Swarm-Timestamp: 1767225600' \
  -H 'X-Swarm-Signature: <signature>' \
  -d '{"approved":true,"approved_by":42}'
```

A signal that releases a matching wait returns HTTP 202:

```json
{
  "run_id": "01J...",
  "signal": "approval_received",
  "accepted": true,
  "duplicate": false,
  "status": "accepted"
}
```

## Signing Test Requests

Webhook tests should sign the exact raw body:

```php
$timestamp = (string) now()->timestamp;
$body = json_encode(['document_id' => 100], JSON_THROW_ON_ERROR);
$signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

$this->postJson('/swarm/webhooks/start/approval-swarm', ['document_id' => 100], [
    'Idempotency-Key' => 'approval-start-100',
    'X-Swarm-Timestamp' => $timestamp,
    'X-Swarm-Signature' => $signature,
])->assertAccepted();
```

## Scheduler

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyMinute();
Schedule::command('swarm:prune')->daily();
```

## What Happened

The start route authenticated the request, reserved the idempotency key, resolved
`ApprovalSwarm` from the container, and dispatched it durably. The signal route
authenticated the request, recorded the signal, and released a matching wait
when one was open. Recovery continues any durable work that is ready after the
webhook boundary.

## Related Documentation

- [Durable Webhooks](../../docs/durable-webhooks.md)
- [Durable Waits And Signals](../../docs/durable-waits-and-signals.md)
- [Audit Evidence Contract](../../docs/audit-evidence-contract.md)
