# Durable Waits And Signals

Durable waits let a swarm run checkpoint and stop at an operator or external
boundary. A waiting run is still active operational state. It resumes when the
application sends a matching run signal or when recovery observes that the wait
timeout has elapsed.

This is Swarm-native checkpointing, not deterministic workflow replay. Provider
calls are never replayed as part of a coroutine. A durable run advances at agent
step boundaries.

```php
$response = ApprovalSwarm::make()->dispatchDurable(
    RunContext::fromTask(['document_id' => $document->id])
        ->withLabels(['tenant' => $tenant->id])
        ->withDetails(['document' => ['id' => $document->id]])
);

app(DurableSwarmManager::class)->wait(
    runId: $response->runId,
    name: 'approval_received',
    reason: 'Waiting for regulatory approval',
    timeoutSeconds: 86400,
);

$response->signal('approval_received', ['approved' => true], idempotencyKey: $request->header('Idempotency-Key'));
```

Swarms may also declare waits with `#[DurableWait]` or return wait definitions
from `RoutesDurableWaits`. Declarative waits are entered after an agent-step
checkpoint, so the run can recover without replaying the provider call that
completed before the wait.

Signals are stored before release is attempted. Idempotency keys are scoped to a
run so retried webhook deliveries do not duplicate signals. Paused runs retain
the signal and do not advance until resumed. Cancelled runs ignore later signals.

Use `swarm:signal` for operator-driven continuation:

```bash
php artisan swarm:signal <run-id> approval_received --payload='{"approved":true}' --idempotency-key=approval-123
```
