# Durable Webhooks

Durable webhook ingress is opt-in and authenticated by default. Laravel Swarm
does not scan application classes or expose every swarm automatically.

```php
use App\Ai\Swarms\ApprovalSwarm;
use BuiltByBerry\LaravelSwarm\Support\SwarmWebhooks;

SwarmWebhooks::routes([
    ApprovalSwarm::class,
]);
```

Production defaults use signed requests:

```php
'durable' => [
    'webhooks' => [
        'enabled' => env('SWARM_WEBHOOKS_ENABLED', false),
        'prefix' => env('SWARM_WEBHOOKS_PREFIX', 'swarm/webhooks'),
        'auth' => [
            'driver' => env('SWARM_WEBHOOK_AUTH_DRIVER', 'signed'),
            'secret' => env('SWARM_WEBHOOK_SECRET'),
        ],
    ],
],
```

The signed driver requires `X-Swarm-Timestamp` and `X-Swarm-Signature`. The
signature is HMAC SHA-256 over `timestamp.raw_body`. Missing secrets, stale
timestamps, and invalid signatures fail closed. The `none` driver is accepted
only in `local` and `testing` environments.

Start and signal endpoints accept `Idempotency-Key` so provider retries do not
duplicate durable starts or run signals.

Start idempotency keys are stored in the durable webhook idempotency table. A
successful start marks the key as `completed` and later matching retries return
the original run ID. Concurrent matching retries while the first request is still
reserved return `409` as in-flight, and the same key with a different request
body returns `409` as a conflict.

If a start request fails after reserving an idempotency key but before creating a
durable run, the reservation is marked `failed`. A later retry with the same key
and same request body can reclaim only that failed reservation; active
`reserved` rows are never reclaimed. This keeps duplicate concurrent delivery
blocked while allowing failed ingress attempts to be retried.

`swarm:prune` removes stale failed or abandoned no-run webhook idempotency rows
using `swarm.context.ttl` as an operational cleanup fallback. That setting is
reused for cleanup only; it is not a dedicated webhook idempotency retention
control. If webhook ingress becomes a major production surface, prefer adding an
explicit retention setting for these rows.
