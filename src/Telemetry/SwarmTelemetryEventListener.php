<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Telemetry;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmChildCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmChildFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmChildStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmProgressRecorded;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Events\SwarmSignalled;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;
use BuiltByBerry\LaravelSwarm\Events\SwarmWaitTimedOut;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

/**
 * Subscribes to swarm lifecycle and package queue events and forwards normalized
 * telemetry to SwarmTelemetryDispatcher. Does not replace lifecycle events.
 */
class SwarmTelemetryEventListener
{
    /**
     * @var array<int, class-string>
     */
    protected const PACKAGE_JOB_CLASSES = [
        InvokeSwarm::class,
        BroadcastSwarm::class,
        AdvanceDurableSwarm::class,
        AdvanceDurableBranch::class,
        ResumeQueuedHierarchicalSwarm::class,
    ];

    public function __construct(
        protected Container $container,
        protected DurableRunStore $durableRuns,
    ) {}

    protected function telemetry(): SwarmTelemetryDispatcher
    {
        return $this->container->make(SwarmTelemetryDispatcher::class);
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(SwarmStarted::class, [$this, 'handleSwarmStarted']);
        $events->listen(SwarmCompleted::class, [$this, 'handleSwarmCompleted']);
        $events->listen(SwarmFailed::class, [$this, 'handleSwarmFailed']);
        $events->listen(SwarmStepStarted::class, [$this, 'handleSwarmStepStarted']);
        $events->listen(SwarmStepCompleted::class, [$this, 'handleSwarmStepCompleted']);
        $events->listen(SwarmPaused::class, [$this, 'handleSwarmPaused']);
        $events->listen(SwarmResumed::class, [$this, 'handleSwarmResumed']);
        $events->listen(SwarmCancelled::class, [$this, 'handleSwarmCancelled']);
        $events->listen(SwarmWaiting::class, [$this, 'handleSwarmWaiting']);
        $events->listen(SwarmWaitTimedOut::class, [$this, 'handleSwarmWaitTimedOut']);
        $events->listen(SwarmSignalled::class, [$this, 'handleSwarmSignalled']);
        $events->listen(SwarmProgressRecorded::class, [$this, 'handleSwarmProgressRecorded']);
        $events->listen(SwarmChildStarted::class, [$this, 'handleSwarmChildStarted']);
        $events->listen(SwarmChildCompleted::class, [$this, 'handleSwarmChildCompleted']);
        $events->listen(SwarmChildFailed::class, [$this, 'handleSwarmChildFailed']);
        $events->listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        $events->listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [$this, 'handleJobFailed']);
    }

    public function handleSwarmStarted(SwarmStarted $event): void
    {
        $this->telemetry()->emit('run.started', [
            'run_id' => $event->runId,
            'parent_run_id' => $event->metadata['parent_run_id'] ?? null,
            'swarm_class' => $event->swarmClass,
            'topology' => $event->topology,
            'execution_mode' => $event->executionMode,
            'status' => 'started',
            ...$this->telemetry()->metadata($event->metadata),
        ]);
    }

    public function handleSwarmCompleted(SwarmCompleted $event): void
    {
        $this->telemetry()->emit('run.completed', [
            'run_id' => $event->runId,
            'parent_run_id' => $event->metadata['parent_run_id'] ?? null,
            'swarm_class' => $event->swarmClass,
            'topology' => $event->topology,
            'execution_mode' => $event->executionMode,
            'status' => 'completed',
            'duration_ms' => $event->durationMs,
            ...$this->telemetry()->metadata($event->metadata),
        ]);
    }

    public function handleSwarmFailed(SwarmFailed $event): void
    {
        $this->telemetry()->emit('run.failed', [
            'run_id' => $event->runId,
            'parent_run_id' => $event->metadata['parent_run_id'] ?? null,
            'swarm_class' => $event->swarmClass,
            'topology' => $event->topology,
            'execution_mode' => $event->executionMode,
            'status' => 'failed',
            'exception_class' => $event->exceptionClass,
            'duration_ms' => $event->durationMs,
            ...$this->telemetry()->metadata($event->metadata),
        ]);
    }

    public function handleSwarmStepStarted(SwarmStepStarted $event): void
    {
        $this->telemetry()->emit('step.started', [
            'run_id' => $event->runId,
            'parent_run_id' => $event->metadata['parent_run_id'] ?? null,
            'swarm_class' => $event->swarmClass,
            'topology' => $event->metadata['topology'] ?? null,
            'execution_mode' => $event->metadata['execution_mode'] ?? null,
            'step_index' => $event->index,
            'agent_class' => $event->agentClass,
            'status' => 'started',
            ...$this->telemetry()->metadata($event->metadata),
        ]);
    }

    public function handleSwarmStepCompleted(SwarmStepCompleted $event): void
    {
        $this->telemetry()->emit('step.completed', [
            'run_id' => $event->runId,
            'parent_run_id' => $event->metadata['parent_run_id'] ?? null,
            'swarm_class' => $event->swarmClass,
            'topology' => $event->topology,
            'execution_mode' => $event->metadata['execution_mode'] ?? null,
            'step_index' => $event->index,
            'agent_class' => $event->agentClass,
            'duration_ms' => $event->durationMs,
            'status' => 'completed',
            ...$this->telemetry()->metadata($event->metadata),
        ]);
    }

    public function handleSwarmPaused(SwarmPaused $event): void
    {
        $this->telemetry()->emit('durable.paused', [
            'run_id' => $event->runId,
            'swarm_class' => $event->swarmClass,
            'topology' => $event->topology,
            'execution_mode' => $event->executionMode,
            'status' => 'paused',
            ...$this->telemetry()->metadata($event->metadata),
        ]);
    }

    public function handleSwarmResumed(SwarmResumed $event): void
    {
        $this->telemetry()->emit('durable.resumed', [
            'run_id' => $event->runId,
            'swarm_class' => $event->swarmClass,
            'topology' => $event->topology,
            'execution_mode' => $event->executionMode,
            'status' => 'resumed',
            ...$this->telemetry()->metadata($event->metadata),
        ]);
    }

    public function handleSwarmCancelled(SwarmCancelled $event): void
    {
        $this->telemetry()->emit('durable.cancelled', [
            'run_id' => $event->runId,
            'swarm_class' => $event->swarmClass,
            'topology' => $event->topology,
            'execution_mode' => $event->executionMode,
            'status' => 'cancelled',
            ...$this->telemetry()->metadata($event->metadata),
        ]);
    }

    public function handleSwarmWaiting(SwarmWaiting $event): void
    {
        $this->telemetry()->emit('wait.started', [
            'run_id' => $event->runId,
            'swarm_class' => $event->swarmClass,
            'topology' => $event->topology,
            'execution_mode' => $event->executionMode,
            'wait_name' => $event->waitName,
            'reason' => $event->reason,
            'status' => 'waiting',
            ...$this->telemetry()->metadata($event->metadata),
        ]);
    }

    public function handleSwarmWaitTimedOut(SwarmWaitTimedOut $event): void
    {
        $this->telemetry()->emit('wait.timed_out', [
            'run_id' => $event->runId,
            'swarm_class' => $event->swarmClass,
            'topology' => $event->topology,
            'execution_mode' => $event->executionMode,
            'wait_name' => $event->waitName,
            'status' => 'timed_out',
        ]);
    }

    public function handleSwarmSignalled(SwarmSignalled $event): void
    {
        $this->telemetry()->emit('signal.received', [
            'run_id' => $event->runId,
            'swarm_class' => $event->swarmClass,
            'topology' => $event->topology,
            'execution_mode' => $event->executionMode,
            'signal_name' => $event->signalName,
            'accepted' => $event->accepted,
            'status' => $event->accepted ? 'accepted' : 'recorded',
        ]);
    }

    public function handleSwarmProgressRecorded(SwarmProgressRecorded $event): void
    {
        $this->telemetry()->emit('progress.recorded', [
            'run_id' => $event->runId,
            'branch_id' => $event->branchId,
            'execution_mode' => $event->executionMode,
            'progress_keys' => array_map('strval', array_keys($event->progress)),
            'status' => 'progress',
        ]);
    }

    public function handleSwarmChildStarted(SwarmChildStarted $event): void
    {
        $this->telemetry()->emit('child.started', [
            'run_id' => $event->childRunId,
            'parent_run_id' => $event->parentRunId,
            'child_run_id' => $event->childRunId,
            'swarm_class' => $event->childSwarmClass,
            'execution_mode' => $event->executionMode,
            'status' => 'started',
        ]);
    }

    public function handleSwarmChildCompleted(SwarmChildCompleted $event): void
    {
        $this->telemetry()->emit('child.completed', [
            'run_id' => $event->childRunId,
            'parent_run_id' => $event->parentRunId,
            'child_run_id' => $event->childRunId,
            'swarm_class' => $event->childSwarmClass,
            'execution_mode' => $event->executionMode,
            'status' => 'completed',
        ]);
    }

    public function handleSwarmChildFailed(SwarmChildFailed $event): void
    {
        $failureKeys = is_array($event->failure)
            ? array_map('strval', array_keys($event->failure))
            : [];

        $this->telemetry()->emit('child.failed', [
            'run_id' => $event->childRunId,
            'parent_run_id' => $event->parentRunId,
            'child_run_id' => $event->childRunId,
            'swarm_class' => $event->childSwarmClass,
            'execution_mode' => $event->executionMode,
            'failure_keys' => $failureKeys,
            'status' => 'failed',
        ]);
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        $this->emitJobTelemetry('job.started', $event->job, 'started');
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $this->emitJobTelemetry('job.completed', $event->job, 'completed');
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $this->emitJobTelemetry('job.failed', $event->job, 'failed', $event->exception);
    }

    protected function emitJobTelemetry(string $category, QueueJobContract $job, string $status, ?Throwable $exception = null): void
    {
        $command = $this->unserializePackageJob($job);

        if ($command === null) {
            return;
        }

        $connection = $job->getConnectionName();
        $queue = $job->getQueue();

        $payload = [
            'run_id' => $this->runIdFromPackageJob($command),
            'swarm_class' => $this->swarmClassFromPackageJob($command),
            'job_class' => $command::class,
            'queue_connection' => $connection,
            'queue_name' => $queue,
            'status' => $status,
        ];

        if ($exception !== null) {
            $payload['exception_class'] = $exception::class;
        }

        $this->telemetry()->emit($category, $payload);
    }

    protected function unserializePackageJob(QueueJobContract $job): ?object
    {
        $payload = $job->payload();
        $serialized = $payload['data']['command'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        try {
            $command = unserialize($serialized, [
                'allowed_classes' => self::PACKAGE_JOB_CLASSES,
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! is_object($command)) {
            return null;
        }

        foreach (self::PACKAGE_JOB_CLASSES as $class) {
            if ($command instanceof $class) {
                return $command;
            }
        }

        return null;
    }

    protected function runIdFromPackageJob(object $command): ?string
    {
        if ($command instanceof InvokeSwarm || $command instanceof BroadcastSwarm) {
            return RunContext::fromPayload($command->task)->runId;
        }

        if ($command instanceof AdvanceDurableSwarm || $command instanceof AdvanceDurableBranch || $command instanceof ResumeQueuedHierarchicalSwarm) {
            return $command->runId;
        }

        return null;
    }

    protected function swarmClassFromPackageJob(object $command): ?string
    {
        if ($command instanceof InvokeSwarm || $command instanceof BroadcastSwarm) {
            return $command->swarmClass;
        }

        if ($command instanceof AdvanceDurableSwarm || $command instanceof AdvanceDurableBranch || $command instanceof ResumeQueuedHierarchicalSwarm) {
            $row = $this->durableRuns->find($command->runId);

            return is_array($row) ? ($row['swarm_class'] ?? null) : null;
        }

        return null;
    }
}
