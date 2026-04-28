# Durable Hierarchical Approval

Shows a coordinator-owned route plan that fans out durable branch jobs, waits
for every branch to finish, then joins the branch outputs into one approval
summary.

Use this pattern when route planning is part of the business process and the
branch work is too important or expensive to replay from the beginning.

This example covers:

- hierarchical routing with a `parallel` node
- durable branch fan-out and join
- `#[DurableParallelFailurePolicy]`
- `dispatchDurable()->onQueue(...)`
- lifecycle events instead of queued callbacks
- recovery requirements for waiting branch boundaries

**Requires:**

- `SWARM_PERSISTENCE_DRIVER=database`
- migrated swarm tables
- a running queue worker for the durable queue
- `swarm:recover` scheduled in Laravel's scheduler
- `swarm:prune` scheduled for retention cleanup

## Swarm

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\ApprovalCoordinator;
use App\Ai\Agents\ApprovalSummarizer;
use App\Ai\Agents\LegalReviewer;
use App\Ai\Agents\SecurityReviewer;
use BuiltByBerry\LaravelSwarm\Attributes\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy as FailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Hierarchical)]
#[DurableParallelFailurePolicy(FailurePolicy::CollectFailures)]
class ApprovalReviewSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ApprovalCoordinator,
            new LegalReviewer,
            new SecurityReviewer,
            new ApprovalSummarizer,
        ];
    }
}
```

`collect_failures` waits for all branch jobs to reach a terminal state before
failing the parent run with branch diagnostics. Use `fail_run` when the first
branch failure should stop the workflow, or `partial_success` when downstream
logic can continue with successful branch outputs.

## Coordinator

The coordinator returns the route plan. It does not call the reviewers itself.

```php
<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class ApprovalCoordinator implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
Return a Laravel Swarm route plan for this approval request.

Allowed worker agents:
- App\Ai\Agents\LegalReviewer
- App\Ai\Agents\SecurityReviewer
- App\Ai\Agents\ApprovalSummarizer

Use a parallel node when legal and security review are independent. The
parallel branches must be worker nodes, branch workers must not define next,
and the parallel node must join into the approval summarizer.
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'start_at' => $schema->string()->required(),
            'nodes' => $schema->object()->required(),
        ];
    }
}
```

## Expected Route Plan

This plan dispatches legal and security review as durable branch jobs. The
parent run waits at `review_parallel` until both branch rows are terminal, then
continues to `summarize`.

```json
{
  "start_at": "review_parallel",
  "nodes": {
    "review_parallel": {
      "type": "parallel",
      "branches": ["legal_review", "security_review"],
      "next": "summarize"
    },
    "legal_review": {
      "type": "worker",
      "agent": "App\\Ai\\Agents\\LegalReviewer",
      "prompt": "Review the approval request for legal and contractual risk."
    },
    "security_review": {
      "type": "worker",
      "agent": "App\\Ai\\Agents\\SecurityReviewer",
      "prompt": "Review the approval request for security and data exposure risk."
    },
    "summarize": {
      "type": "worker",
      "agent": "App\\Ai\\Agents\\ApprovalSummarizer",
      "prompt": "Write the final approval recommendation.",
      "with_outputs": {
        "legal_notes": "legal_review",
        "security_notes": "security_review"
      },
      "next": "finish"
    },
    "finish": {
      "type": "finish",
      "output_from": "summarize"
    }
  }
}
```

Branch workers intentionally do not define `next`. The parallel node owns the
join target through `next`, and the downstream summarizer pulls branch outputs
with `with_outputs`.

## Dispatch

```php
use App\Ai\Swarms\ApprovalReviewSwarm;

$response = ApprovalReviewSwarm::make()
    ->dispatchDurable([
        'approval_id' => 9842,
        'request_type' => 'vendor data access',
        'business_owner' => 'Finance Operations',
        'risk_tier' => 'high',
    ])
    ->onQueue('swarm-durable');

$runId = $response->runId;
```

Only pass plain data. Store large documents, contracts, and evidence files in
your own application storage, then pass IDs or short excerpts through the
swarm task.

## Durable Flow

1. The first durable job runs `ApprovalCoordinator`.
2. Laravel Swarm validates and persists the route plan and route cursor.
3. The parallel node creates one durable branch row for each branch worker.
4. Branch jobs run with independent leases and checkpoint their own output,
   usage, failures, queue routing, and attempts.
5. The parent run stays `waiting` while branches are active.
6. When every branch row is terminal, recovery or the last branch checkpoint
   releases the parent to the join step.
7. `ApprovalSummarizer` receives the named branch outputs and writes the final
   recommendation.
8. The final durable step marks the run completed and dispatches
   `SwarmCompleted`.

## Events

Durable responses do not support `then()` or `catch()`. Use lifecycle events or
persisted history to continue your application workflow.

```php
use App\Ai\Swarms\ApprovalReviewSwarm;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use Illuminate\Support\Facades\Event;

Event::listen(SwarmCompleted::class, function (SwarmCompleted $event): void {
    if ($event->swarmClass !== ApprovalReviewSwarm::class) {
        return;
    }

    logger()->info('Approval review completed', [
        'run_id' => $event->runId,
        'output' => $event->output,
    ]);
});

Event::listen(SwarmFailed::class, function (SwarmFailed $event): void {
    if ($event->swarmClass !== ApprovalReviewSwarm::class) {
        return;
    }

    report($event->exception);
});
```

## Operations

While the parent run is waiting for branches, pause and cancel act at that
branch boundary. Active branch jobs may finish their current provider call, but
the parent will not join while paused. Cancelling the waiting run marks
non-terminal branches cancelled so stale branch workers become inert when they
checkpoint.

Schedule recovery so the parent can be released after all branches checkpoint,
even if a worker exits before dispatching the join job:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyFiveMinutes();
Schedule::command('swarm:prune')->daily();
```

Active route plans and branch outputs can contain worker prompts and
intermediate review notes. Treat durable runtime storage as sensitive
operational data, and use capture settings plus short retention windows for
regulated workflows.

