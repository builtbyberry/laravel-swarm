# Operations Dashboard

Shows how to turn Laravel Swarm lifecycle events into an application dashboard.

Use this pattern when operators need to see recent runs, step progress, failures,
and durable controls in the browser.

This example teaches:

- listen to package lifecycle events;
- store a small application-owned event record;
- broadcast an application event with Reverb, Pusher, Soketi, or your preferred
  broadcaster;
- use Pulse for aggregate metrics and your own dashboard for run-level UX.

## Prerequisites

- Laravel events are enabled for your application.
- Run the package migrations if you want database-backed history or durable
  controls alongside the dashboard.
- Configure broadcasting only if the UI needs live updates.
- Install and configure Laravel Pulse only if you want aggregate metrics cards.

## Register Listeners

### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Listeners\RecordSwarmLifecycleEvent;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([
            SwarmStarted::class,
            SwarmStepStarted::class,
            SwarmStepCompleted::class,
            SwarmCompleted::class,
            SwarmFailed::class,
            SwarmPaused::class,
            SwarmResumed::class,
            SwarmCancelled::class,
        ] as $event) {
            Event::listen($event, RecordSwarmLifecycleEvent::class);
        }
    }
}
```

## Record And Broadcast

Keep the record small. Store previews and operational fields, not full provider
payloads. Capture flags may already replace input, output, and failure messages
with `[redacted]`.

### `database/migrations/xxxx_xx_xx_xxxxxx_create_swarm_lifecycle_events_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->string('event');
            $table->string('swarm_class');
            $table->string('topology')->nullable();
            $table->string('execution_mode')->nullable();
            $table->string('status');
            $table->unsignedInteger('step_index')->nullable();
            $table->string('agent_class')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('input_preview')->nullable();
            $table->text('output_preview')->nullable();
            $table->text('error_preview')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_lifecycle_events');
    }
};
```

### `app/Models/SwarmLifecycleEvent.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SwarmLifecycleEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function broadcastPayload(): array
    {
        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'event' => $this->event,
            'swarm_name' => Str::headline(class_basename($this->swarm_class)),
            'agent_name' => is_string($this->agent_class)
                ? Str::headline(class_basename($this->agent_class))
                : null,
            'status' => $this->status,
            'input_preview' => $this->input_preview,
            'output_preview' => $this->output_preview,
            'error_preview' => $this->error_preview,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
```

### `app/Listeners/RecordSwarmLifecycleEvent.php`

```php
<?php

namespace App\Listeners;

use App\Events\SwarmLifecycleEventRecorded;
use App\Models\SwarmLifecycleEvent;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use Illuminate\Support\Str;

class RecordSwarmLifecycleEvent
{
    public function handle(object $event): void
    {
        $record = SwarmLifecycleEvent::query()->create([
            'run_id' => $event->runId,
            'event' => $this->name($event),
            'swarm_class' => $event->swarmClass,
            'topology' => $event->topology ?? null,
            'execution_mode' => $event->executionMode ?? null,
            'status' => $this->status($event),
            'step_index' => $event->index ?? null,
            'agent_class' => $event->agentClass ?? null,
            'duration_ms' => $event->durationMs ?? null,
            'input_preview' => $this->preview($event->input ?? null),
            'output_preview' => $this->preview($event->output ?? null),
            'error_preview' => $event instanceof SwarmFailed
                ? $this->preview($event->exception->getMessage())
                : null,
            'metadata' => $event->metadata ?? [],
            'occurred_at' => now('UTC'),
        ]);

        event(new SwarmLifecycleEventRecorded($record->broadcastPayload()));
    }

    protected function name(object $event): string
    {
        return match ($event::class) {
            SwarmStarted::class => 'swarm.started',
            SwarmStepStarted::class => 'swarm.step.started',
            SwarmStepCompleted::class => 'swarm.step.completed',
            SwarmCompleted::class => 'swarm.completed',
            SwarmFailed::class => 'swarm.failed',
            SwarmPaused::class => 'swarm.paused',
            SwarmResumed::class => 'swarm.resumed',
            SwarmCancelled::class => 'swarm.cancelled',
            default => class_basename($event),
        };
    }

    protected function status(object $event): string
    {
        return match ($event::class) {
            SwarmStarted::class, SwarmStepStarted::class, SwarmStepCompleted::class, SwarmResumed::class => 'running',
            SwarmCompleted::class => 'completed',
            SwarmFailed::class => 'failed',
            SwarmPaused::class => 'paused',
            SwarmCancelled::class => 'cancelled',
            default => 'recorded',
        };
    }

    protected function preview(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES);

        return Str::of($text ?: '')->squish()->limit(320)->toString();
    }
}
```

## Broadcast An App-Owned Event

Do not broadcast package event objects directly. Wrap them in an application
event so your frontend contract stays under your control.

```php
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class SwarmLifecycleEventRecorded implements ShouldBroadcastNow
{
    public function __construct(public array $payload) {}

    public function broadcastOn(): Channel
    {
        return new Channel('swarm.operations');
    }

    public function broadcastAs(): string
    {
        return 'swarm.lifecycle';
    }
}
```

## Dashboard Shape

Most dashboards need two data sources:

- lifecycle records for recent activity and live updates;
- a run inspector endpoint for full run detail when a row is selected.

Pulse is a good fit for aggregate package metrics such as totals, failures,
topology mix, and slow steps. Use lifecycle events when the browser needs a
run-level operations feed.
