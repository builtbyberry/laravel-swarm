# Human-In-The-Loop Support Review

Shows a durable customer-support workflow where AI drafts a sensitive reply,
the swarm pauses for supervisor review, the app broadcasts a notification, and
the supervisor's decision resumes the workflow.

Use this pattern when an AI workflow can prepare most of the work but a human
must approve, reject, or redirect the final action.

This example teaches:

- durable waits are the human checkpoint;
- `RoutesDurableWaits` can enter that checkpoint after a specific step;
- lifecycle events can trigger app-owned notifications;
- the review UI belongs to your Laravel application;
- `DurableSwarmManager::signal()` resumes the swarm after review;
- frontend state is driven by `run_id`, inspection endpoints, and broadcasts.

## Prerequisites

- Laravel AI is configured in your application.
- `SWARM_PERSISTENCE_DRIVER=database`
- `SWARM_CAPTURE_ACTIVE_CONTEXT=true`
- Package migrations have run.
- A queue worker is running.
- `swarm:recover` is scheduled.
- Laravel broadcasting is configured when the UI needs live notifications.

## Swarm

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\FinalSupportReplyAgent;
use App\Ai\Agents\SupportDraftReplyAgent;
use App\Ai\Agents\SupportTriageAgent;
use BuiltByBerry\LaravelSwarm\Attributes\DurableDetails;
use BuiltByBerry\LaravelSwarm\Attributes\DurableLabels;
use BuiltByBerry\LaravelSwarm\Attributes\Timeout;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableWaits;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

#[Timeout(900)]
#[DurableLabels(['workflow' => 'support-review'])]
#[DurableDetails(['review_type' => 'supervisor'])]
class SupportEscalationSwarm implements Swarm, RoutesDurableWaits
{
    use Runnable;

    public function agents(): array
    {
        return [
            new SupportTriageAgent,
            new SupportDraftReplyAgent,
            new FinalSupportReplyAgent,
        ];
    }

    public function durableWaits(RunContext $context): array
    {
        if (($context->metadata['completed_steps'] ?? 0) < 2) {
            return [];
        }

        return [
            [
                'name' => 'supervisor_reviewed',
                'timeout' => 86400,
                'reason' => 'Waiting for supervisor review',
            ],
        ];
    }
}
```

The first two agents triage the ticket and draft a reply. The declared durable
wait is returned only after those two steps are checkpointed. After the
supervisor signal is received, `FinalSupportReplyAgent` can read the review
decision from the run context and produce the final response.

## Start The Review

```php
use App\Ai\Swarms\SupportEscalationSwarm;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

public function store(Request $request): JsonResponse
{
    $data = $request->validate([
        'ticket_id' => ['required', 'integer'],
        'customer_tone' => ['nullable', 'string', 'max:50'],
    ]);

    $ticket = Ticket::query()->findOrFail($data['ticket_id']);

    $response = SupportEscalationSwarm::make()
        ->dispatchDurable(
            RunContext::fromTask([
                'ticket_id' => $ticket->id,
                'subject' => $ticket->subject,
                'message' => $ticket->latest_message,
                'customer_tone' => $data['customer_tone'] ?? null,
            ])
                ->withLabels([
                    'ticket_id' => $ticket->id,
                    'team_id' => $ticket->team_id,
                ])
                ->withDetails([
                    'ticket' => [
                        'id' => $ticket->id,
                        'subject' => $ticket->subject,
                    ],
                ])
        )
        ->onQueue('swarm-durable');

    return response()->json([
        'run_id' => $response->runId,
        'ticket_id' => $ticket->id,
        'status' => 'review_started',
    ], 202);
}
```

## Broadcast The Review Request

Listen for `SwarmWaiting` and broadcast your own application event. The package
does not own your notification, authorization, or review inbox.

```php
use App\Ai\Swarms\SupportEscalationSwarm;
use App\Events\SupportReviewRequested;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;
use Illuminate\Support\Facades\Event;

Event::listen(SwarmWaiting::class, function (SwarmWaiting $event): void {
    if ($event->swarmClass !== SupportEscalationSwarm::class) {
        return;
    }

    if ($event->waitName !== 'supervisor_reviewed') {
        return;
    }

    SupportReviewRequested::dispatch(
        runId: $event->runId,
        metadata: $event->metadata,
    );
});
```

Example app-owned broadcast event:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class SupportReviewRequested implements ShouldBroadcast
{
    public function __construct(
        public string $runId,
        public array $metadata = [],
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('support.reviews');
    }

    public function broadcastAs(): string
    {
        return 'support.review.requested';
    }

    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'metadata' => $this->metadata,
        ];
    }
}
```

## Review Endpoint

Expose a read endpoint that your UI can poll or refresh after receiving the
broadcast. Keep the response app-owned so you control which prompt, draft, and
ticket fields are visible to the reviewer.

```php
use App\Ai\Agents\SupportDraftReplyAgent;
use BuiltByBerry\LaravelSwarm\Facades\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Http\JsonResponse;

public function show(string $runId, DurableSwarmManager $manager): JsonResponse
{
    $detail = $manager->inspect($runId)->toArray();
    $history = SwarmHistory::find($runId);

    return response()->json([
        'run_id' => $runId,
        'status' => $detail['run']['status'] ?? null,
        'waits' => $detail['waits'] ?? [],
        'ticket' => $detail['details']['ticket'] ?? null,
        'draft' => collect($history['steps'] ?? [])
            ->firstWhere('agent_class', SupportDraftReplyAgent::class)['output'] ?? null,
    ]);
}
```

If output capture is disabled, the persisted draft may be redacted. In that
case, store the review draft in your own application table from a lifecycle
listener or agent tool callback.

## Approve, Reject, Or Revise

```php
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

public function review(string $runId, Request $request, DurableSwarmManager $manager): JsonResponse
{
    $data = $request->validate([
        'decision' => ['required', 'string', 'in:approved,rejected,revise'],
        'comment' => ['nullable', 'string', 'max:2000'],
        'edited_reply' => ['nullable', 'string', 'max:10000'],
    ]);

    $result = $manager->signal(
        runId: $runId,
        name: 'supervisor_reviewed',
        payload: [
            'decision' => $data['decision'],
            'comment' => $data['comment'] ?? null,
            'edited_reply' => $data['edited_reply'] ?? null,
            'reviewed_by' => $request->user()->id,
        ],
        idempotencyKey: $request->header('Idempotency-Key'),
    );

    return response()->json([
        'run_id' => $result->runId,
        'accepted' => $result->accepted,
        'duplicate' => $result->duplicate,
        'status' => $result->status,
    ], $result->accepted ? 202 : 200);
}
```

`accepted=true` means the matching wait was released. The final agent can use
the signal payload to decide whether to send the approved draft, incorporate an
edited reply, or generate an escalation summary.

## Final Agent Context

Inside the final agent or a service it calls, read the signal payload from the
run context:

```php
$review = $context->signalPayload('supervisor_reviewed');

return match ($review['decision'] ?? null) {
    'approved' => 'Send the approved support reply.',
    'revise' => 'Use the supervisor edits and produce the final reply.',
    'rejected' => 'Do not send. Create an escalation note for a human owner.',
    default => 'No review decision is available.',
};
```

## Frontend Pseudocode

The frontend below is intentionally framework-neutral. It shows the moving
parts your Vue, React, Livewire, or Inertia UI would own.

```js
// reviewStore.js
state = {
    pending: [],
    activeRun: null,
    loading: false,
};

function bootReviewNotifications() {
    Echo.private('support.reviews')
        .listen('.support.review.requested', async (event) => {
            pending.push(event.run_id);
            await loadReview(event.run_id);
            showToast('AI support reply needs review');
        });
}

async function loadReview(runId) {
    loading = true;

    activeRun = await http.get(`/support/reviews/${runId}`);

    loading = false;
}

async function submitReview({ decision, comment, editedReply }) {
    const response = await http.post(
        `/support/reviews/${activeRun.run_id}`,
        {
            decision,
            comment,
            edited_reply: editedReply,
        },
        {
            headers: {
                'Idempotency-Key': `support-review:${activeRun.run_id}:${currentUser.id}`,
            },
        },
    );

    activeRun.status = response.status;
}
```

Example component shape:

```vue
<template>
  <section>
    <header>
      <h1>Support Review</h1>
      <p>{{ activeRun.ticket.subject }}</p>
    </header>

    <article>
      <h2>AI Draft</h2>
      <textarea v-model="editedReply" />
    </article>

    <aside>
      <label>
        Reviewer comment
        <textarea v-model="comment" />
      </label>

      <button @click="submit('approved')">Approve</button>
      <button @click="submit('revise')">Approve With Edits</button>
      <button @click="submit('rejected')">Reject</button>
    </aside>
  </section>
</template>
```

## Scheduler

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyMinute();
Schedule::command('swarm:prune')->daily();
```

## What Happened

The durable swarm ran until it reached the supervisor review wait. The
application heard `SwarmWaiting`, broadcast a private notification, and let the
reviewer inspect an app-owned review endpoint. When the reviewer submitted a
decision, the controller signalled the durable run. Laravel Swarm released the
wait and dispatched the next durable step, where the final agent used the
review decision to finish the workflow.

## Related Documentation

- [Durable Waits And Signals](../../docs/durable-waits-and-signals.md)
- [Operations Dashboard](../operations-dashboard/README.md)
- [Run Inspector](../run-inspector/README.md)
