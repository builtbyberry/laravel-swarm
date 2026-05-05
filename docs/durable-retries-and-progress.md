# Durable Retries And Progress

Durable retries let a checkpointed swarm retry failed agent steps or branch jobs
without replaying the entire workflow. Durable progress records let long-running
steps expose latest-state operational status for inspectors and dashboards.

These are Swarm terms around agent steps and branch jobs. They are not an
activity DSL and they do not make provider calls deterministic.

## When To Use Retries

Use durable retries when a failed step may succeed later:

- a provider returns a transient error
- a dependency is temporarily unavailable
- a branch job fails because of a queue worker interruption
- a long-running tool call crosses an external service boundary

Do not retry validation failures, authorization failures, malformed input, or
other deterministic application errors. Mark those exception classes as
non-retryable.

## Prerequisites

Durable retries require durable execution:

```env
SWARM_PERSISTENCE_DRIVER=database
SWARM_CAPTURE_ACTIVE_CONTEXT=true
```

Schedule recovery so due retries are dispatched:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyMinute();
```

## Declaring Retry Policy With An Attribute

Use `#[DurableRetry]` on a swarm when the same policy should apply broadly:

```php
use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;

#[DurableRetry(maxAttempts: 3, backoffSeconds: [10, 60, 300])]
class ComplianceReviewSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ComplianceIntake,
            new ComplianceExtractor,
            new ComplianceRiskReviewer,
            new ComplianceSummarizer,
        ];
    }
}
```

Use `nonRetryable` for exceptions that should fail immediately:

```php
use App\Exceptions\InvalidComplianceDocument;

#[DurableRetry(
    maxAttempts: 3,
    backoffSeconds: [10, 60, 300],
    nonRetryable: [InvalidComplianceDocument::class],
)]
class ComplianceReviewSwarm implements Swarm
{
    use Runnable;
}
```

`maxAttempts` counts failed executions. The first failed execution records
attempt `1`. Retryable failures checkpoint `retry_attempt` and `next_retry_at`,
clear the lease, and return the run or branch to `pending`.

## Configuring Retries In Code

Use `ConfiguresDurableRetries` when policy depends on the agent class:

```php
use BuiltByBerry\LaravelSwarm\Contracts\ConfiguresDurableRetries;
use BuiltByBerry\LaravelSwarm\Responses\DurableRetryPolicy;

class ComplianceReviewSwarm implements Swarm, ConfiguresDurableRetries
{
    use Runnable;

    public function durableRetryPolicy(): DurableRetryPolicy
    {
        return new DurableRetryPolicy(
            maxAttempts: 2,
            backoffSeconds: [30, 120],
        );
    }

    public function durableAgentRetryPolicy(string $agentClass): ?DurableRetryPolicy
    {
        return match ($agentClass) {
            ComplianceExtractor::class => new DurableRetryPolicy(
                maxAttempts: 4,
                backoffSeconds: [10, 30, 120, 300],
            ),
            default => null,
        };
    }
}
```

Policy precedence is:

1. agent-specific interface policy
2. agent attribute
3. swarm interface policy
4. swarm attribute
5. no retry

## How Recovery Dispatches Retries

`swarm:recover` dispatches due retries without waiting for stale-lease recovery:

```bash
php artisan swarm:recover
php artisan swarm:recover --run-id=<run-id>
php artisan swarm:recover --swarm='App\Ai\Swarms\ComplianceReviewSwarm' --limit=25
```

Retry jobs use the durable queue routing recorded for the run. If you chain
`onConnection()` or `onQueue()` on `dispatchDurable()`, later recovery dispatches
continue using that routing.

## Recording Progress

Progress records are latest-state operational data for long-running agent steps,
tool work, or branch jobs. They are intentionally not an append-only event log.

Record progress through the manager:

```php
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;

app(DurableSwarmManager::class)->recordProgress($runId, progress: [
    'stage' => 'fetching-source-documents',
    'completed' => 12,
    'total' => 30,
]);
```

For branch-specific progress, pass the branch ID:

```php
app(DurableSwarmManager::class)->recordProgress(
    runId: $runId,
    branchId: 'risk-review',
    progress: [
        'stage' => 'reviewing',
        'completed' => 2,
        'total' => 5,
    ],
);
```

Use stable, small payloads. Progress may be displayed in dashboards, logged, or
included in inspection responses.

## Inspecting Progress

Use the console:

```bash
php artisan swarm:progress <run-id>
php artisan swarm:inspect <run-id> --json
```

Or inspect from application code:

```php
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;

$detail = app(DurableSwarmManager::class)->inspect($runId);

$detail->progress;
```

## Edge Cases

- Retry backoff uses the last configured delay when attempts exceed the
  backoff array length.
- Non-retryable exceptions fail the run or branch immediately.
- Branch retries are tracked separately from top-level run retries.
- Progress is overwritten as latest state; use lifecycle events or audit sinks
  for append-only history.
- Progress payloads may contain sensitive operational detail and follow durable
  retention and redaction expectations.

## Testing

Use fake assertions for application intent:

```php
ComplianceReviewSwarm::fake()
    ->recordDurableProgress(['stage' => 'reviewing'])
    ->recordDurableRetry(['max_attempts' => 3]);

ComplianceReviewSwarm::assertDurableProgressRecorded(['stage' => 'reviewing']);
ComplianceReviewSwarm::assertDurableRetryScheduled(['max_attempts' => 3]);
```

Use database-backed feature tests for lease clearing, retry scheduling, due
retry recovery, branch retries, and progress inspection.

## Related Documentation

- [Durable Execution](durable-execution.md)
- [Maintenance](maintenance.md#scheduling)
- [Testing](testing.md#database-backed-durable-execution)
- [Durable Runtime Architecture](durable-runtime-architecture.md)
