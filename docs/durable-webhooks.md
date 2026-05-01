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
