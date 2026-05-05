# Durable Webhooks

Durable webhooks expose authenticated HTTP ingress for starting durable swarms
and sending durable signals. They are opt in. Laravel Swarm does not scan your
application classes or expose every swarm automatically.

Use webhooks when a trusted external system needs to start a durable workflow or
continue one that is waiting on a callback.

## When To Use Webhooks

Use durable webhooks for:

- vendor callbacks that should release a durable wait
- external systems that should start approved durable swarms
- retryable ingress with idempotency keys
- integration flows where the response should return a `run_id`

Do not expose durable webhooks for general public prompt submission. Register
only the swarm classes that are intended for webhook starts, and keep
authentication enabled outside local testing.

## Prerequisites

Durable webhooks require durable execution and routes:

```env
SWARM_PERSISTENCE_DRIVER=database
SWARM_CAPTURE_ACTIVE_CONTEXT=true
SWARM_WEBHOOKS_ENABLED=true
SWARM_WEBHOOK_SECRET=base64-or-random-shared-secret
```

Schedule recovery for durable continuation:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyMinute();
```

## Registering Routes

Register only the swarms that should be startable from webhook ingress:

```php
use App\Ai\Swarms\ApprovalSwarm;
use BuiltByBerry\LaravelSwarm\Support\SwarmWebhooks;

SwarmWebhooks::routes([
    ApprovalSwarm::class,
]);
```

With the default prefix, this registers:

```text
POST /swarm/webhooks/start/approval-swarm
POST /swarm/webhooks/signal/{runId}/{signal}
```

If `swarm.durable.webhooks.enabled` is false, `SwarmWebhooks::routes()` returns
without registering routes.

## Starting A Durable Swarm

Send a JSON request to the start route:

```bash
curl -X POST https://app.test/swarm/webhooks/start/approval-swarm \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: approval-start-100' \
  -H 'X-Swarm-Timestamp: 1767225600' \
  -H 'X-Swarm-Signature: <signature>' \
  -d '{"document_id":100}'
```

The route resolves the registered swarm from Laravel's container and dispatches
it with `dispatchDurable()`. A successful start returns HTTP 202:

```json
{
  "run_id": "01J..."
}
```

## Sending A Signal

Send a JSON request to the signal route:

```bash
curl -X POST https://app.test/swarm/webhooks/signal/01J.../approval_received \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: approval-signal-100' \
  -H 'X-Swarm-Timestamp: 1767225600' \
  -H 'X-Swarm-Signature: <signature>' \
  -d '{"approved":true,"approved_by":42}'
```

The route calls `DurableSwarmManager::signal()` and returns HTTP 202 when the
signal is accepted:

```json
{
  "run_id": "01J...",
  "signal": "approval_received",
  "accepted": true,
  "duplicate": false,
  "status": "accepted"
}
```

If the signal is a duplicate, the response is HTTP 200 with the duplicate flag.

## Authentication Drivers

Production defaults use signed requests:

```php
'durable' => [
    'webhooks' => [
        'enabled' => env('SWARM_WEBHOOKS_ENABLED', false),
        'prefix' => env('SWARM_WEBHOOKS_PREFIX', 'swarm/webhooks'),
        'idempotency_ttl' => (int) env('SWARM_WEBHOOK_IDEMPOTENCY_TTL', 3600),
        'auth' => [
            'driver' => env('SWARM_WEBHOOK_AUTH_DRIVER', 'signed'),
            'secret' => env('SWARM_WEBHOOK_SECRET'),
        ],
    ],
],
```

The signed driver requires `X-Swarm-Timestamp` and `X-Swarm-Signature`. The
signature is HMAC SHA-256 over `timestamp.raw_body`. Missing secrets, stale
timestamps, and invalid signatures fail closed.

The token driver requires `SWARM_WEBHOOK_TOKEN` and authenticates with the
configured bearer token.

For application-owned authentication, set:

```env
SWARM_WEBHOOK_AUTH_DRIVER=callback
SWARM_WEBHOOK_AUTH_CALLBACK=App\\Support\\AuthorizeSwarmWebhook
```

The callback receives the `Illuminate\Http\Request` and must return strict
`true` to authorize the request. Supported callback shapes are native PHP
callables already present in config, invokable class names resolved through the
container, and `Class@method` strings resolved through the container.

Invalid or blank callback configuration fails during route registration so
routes are not exposed with a broken authenticator.

## Local Testing Without Authentication

The `none` driver is accepted only in `local` and `testing` environments:

```env
SWARM_WEBHOOK_AUTH_DRIVER=none
```

Never use `none` in production or staging. In any environment other than
`local` or `testing`, `SwarmWebhooks::routes()` throws a `SwarmException` and
does not expose routes.

## Idempotency

Start and signal endpoints accept `Idempotency-Key`.

For starts, the idempotency key is scoped to the swarm class and request body:

- completed duplicate starts return the original `run_id`
- concurrent matching starts return HTTP 409 while the first request is in flight
- the same key with a different request body returns HTTP 409
- failed reservations can be retried with the same key and same body

For signals, the idempotency key is scoped to the run and signal handling path,
so retried webhook deliveries do not duplicate signals.

`swarm:prune` removes stale failed or abandoned no-run webhook idempotency rows
using `swarm.durable.webhooks.idempotency_ttl`. Completed idempotency rows tied
to a durable run follow durable history retention.

## Security Notes

- Register only intended swarm classes.
- Keep webhook task payloads plain, small, and validated by your swarm or agents.
- Use signed, token, or callback authentication in all deployed environments.
- Rotate shared secrets through your application's normal secret-management path.
- Treat webhook payloads and signal payloads as sensitive operational data.
- Use audit evidence when webhook ingress matters for compliance.

## Testing

Webhook tests should sign the exact raw request body:

```php
$timestamp = (string) now()->timestamp;
$body = json_encode(['document_id' => 100], JSON_THROW_ON_ERROR);
$signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
```

Assert unsigned, stale, invalid-signature, duplicate, conflict, and in-flight
requests according to your integration contract.

Use database-backed feature tests for start idempotency, signal idempotency, and
wait release. `SwarmFake` does not execute webhook routing or durable runtime
coordination.

## Related Documentation

- [Durable Waits And Signals](durable-waits-and-signals.md)
- [Durable Execution](durable-execution.md)
- [Audit Evidence Contract](audit-evidence-contract.md)
- [Durable Runtime Architecture](durable-runtime-architecture.md)
