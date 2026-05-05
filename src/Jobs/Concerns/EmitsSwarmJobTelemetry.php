<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs\Concerns;

use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Telemetry\PackageJobTelemetryState;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Throwable;

trait EmitsSwarmJobTelemetry
{
    public int $enqueuedAtMs;

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withSwarmJobTelemetry(callable $callback): mixed
    {
        $startedAt = MonotonicTime::now();
        $startedAtMs = self::telemetryEpochMilliseconds();

        $this->emitSwarmJobTelemetry('job.started', 'started', null, null, $startedAtMs);

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $durationMs = MonotonicTime::elapsedMilliseconds($startedAt);
            $this->emitSwarmJobTelemetry('job.failed', 'failed', $durationMs, $exception, $startedAtMs);
            $this->swarmJobTelemetryState()->markFailed($this->swarmJobTelemetryKey());

            throw $exception;
        }

        $this->emitSwarmJobTelemetry(
            category: 'job.completed',
            status: 'completed',
            durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
            exception: null,
            startedAtMs: $startedAtMs,
        );

        return $result;
    }

    protected static function telemetryEpochMilliseconds(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    protected function swarmJobTelemetryKey(): string
    {
        return implode(':', [
            $this->telemetryJobClass(),
            $this->telemetryRunId() ?? '',
            $this->telemetryJobId() ?? '',
            (string) $this->telemetryAttempt(),
        ]);
    }

    protected function emitSwarmJobTelemetry(
        string $category,
        string $status,
        ?int $durationMs,
        ?Throwable $exception,
        int $startedAtMs,
    ): void {
        $payload = [
            'run_id' => $this->telemetryRunId(),
            'swarm_class' => $this->telemetrySwarmClass(),
            'job_class' => $this->telemetryJobClass(),
            'job_id' => $this->telemetryJobId(),
            'attempt' => $this->telemetryAttempt(),
            'queue_connection' => $this->telemetryQueueConnection(),
            'queue_name' => $this->telemetryQueueName(),
            'queue_wait_ms' => $this->telemetryElapsedSinceEnqueue($startedAtMs),
            'status' => $status,
        ];

        if ($durationMs !== null) {
            $payload['duration_ms'] = $durationMs;
            $payload['total_elapsed_ms'] = $this->telemetryElapsedSinceEnqueue(self::telemetryEpochMilliseconds());
        }

        if ($exception !== null) {
            $payload['exception_class'] = $exception::class;
        }

        Container::getInstance()->make(SwarmTelemetryDispatcher::class)->emit($category, $payload);
    }

    protected function telemetryElapsedSinceEnqueue(int $endedAtMs): ?int
    {
        if (! isset($this->enqueuedAtMs)) {
            return null;
        }

        return max(0, $endedAtMs - $this->enqueuedAtMs);
    }

    protected function telemetryJobClass(): string
    {
        return $this::class;
    }

    protected function telemetryJobId(): ?string
    {
        $job = $this->telemetryQueueJob();

        if ($job === null) {
            return null;
        }

        $id = $job->uuid() ?: $job->getJobId();

        return is_string($id) && $id !== '' ? $id : null;
    }

    protected function telemetryAttempt(): int
    {
        return $this->telemetryQueueJob()?->attempts() ?? 1;
    }

    protected function telemetryQueueConnection(): ?string
    {
        return $this->telemetryQueueJob()?->getConnectionName() ?? $this->connection;
    }

    protected function telemetryQueueName(): ?string
    {
        return $this->telemetryQueueJob()?->getQueue() ?? $this->queue;
    }

    protected function telemetryQueueJob(): ?QueueJobContract
    {
        return $this->job instanceof QueueJobContract ? $this->job : null;
    }

    protected function swarmJobTelemetryState(): PackageJobTelemetryState
    {
        return Container::getInstance()->make(PackageJobTelemetryState::class);
    }

    abstract protected function telemetryRunId(): ?string;

    abstract protected function telemetrySwarmClass(): ?string;
}
