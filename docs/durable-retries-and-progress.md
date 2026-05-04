# Durable Retries And Progress

Durable retry policy is Swarm terminology around agent steps and branch jobs.
It is not an activity DSL. Retry settings can be declared on a swarm with
`#[DurableRetry]` or returned through `ConfiguresDurableRetries`.

Policy precedence is agent-specific interface policy, agent attribute, swarm
interface policy, swarm attribute, then no retry. Retry attempts count failed
executions; the first failed execution records attempt `1`. Retryable failures
checkpoint `retry_attempt` and `next_retry_at`, clear the lease, and return the
run or branch to `pending`. `swarm:recover` dispatches due retries without
waiting for stale-lease recovery.

Progress records are latest-state operational data for long-running agent steps,
tool work, or branch jobs. They are intentionally not an append-only event log.

```php
app(DurableSwarmManager::class)->recordProgress($runId, progress: [
    'stage' => 'fetching-source-documents',
    'completed' => 12,
    'total' => 30,
]);
```

Inspect progress with:

```bash
php artisan swarm:progress <run-id>
php artisan swarm:inspect <run-id> --json
```

Progress payloads can contain sensitive operational detail and follow the same
retention and redaction expectations as durable context, route plans, and branch
outputs.

`recordProgress()` is delegated through `DurableSwarmManager` to the inspection
path of the durable graph. For the code map, see
[Durable Runtime Architecture](durable-runtime-architecture.md).
