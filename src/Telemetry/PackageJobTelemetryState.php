<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Telemetry;

/**
 * Process-local guard for package job telemetry emitted inside job handlers.
 *
 * A marker is only useful for the attempt that left it, so the guard bounds
 * itself two ways: {@see forgetJob()} drops a queue job's marker once its
 * attempt is over, and {@see markFailed()} evicts the oldest marker beyond
 * {@see MAX_PENDING} so a marker nobody discards cannot accumulate for the
 * life of the process. Evicting a marker that is still pending costs at most
 * one duplicate fallback record for that attempt.
 *
 * @internal
 */
class PackageJobTelemetryState
{
    protected const MAX_PENDING = 256;

    /**
     * De-dup key => id of the queue job that left it, or null when the handler
     * ran without an identifiable queue job.
     *
     * @var array<string, string|null>
     */
    protected array $failedJobs = [];

    /**
     * @param  string|null  $jobId  The queue job's id, so {@see forgetJob()} can find the
     *                              marker later; null leaves it to {@see consumeFailed()} and the cap.
     */
    public function markFailed(string $key, ?string $jobId): void
    {
        $this->failedJobs[$key] = $jobId;

        if (count($this->failedJobs) > self::MAX_PENDING) {
            unset($this->failedJobs[array_key_first($this->failedJobs)]);
        }
    }

    public function consumeFailed(string $key): bool
    {
        if (! array_key_exists($key, $this->failedJobs)) {
            return false;
        }

        unset($this->failedJobs[$key]);

        return true;
    }

    /**
     * Discard every marker the given queue job left, without reporting it.
     */
    public function forgetJob(string $jobId): void
    {
        foreach ($this->failedJobs as $key => $pending) {
            if ($pending === $jobId) {
                unset($this->failedJobs[$key]);
            }
        }
    }

    public function pendingCount(): int
    {
        return count($this->failedJobs);
    }
}
