<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Telemetry;

/**
 * Process-local guard for package job telemetry emitted inside job handlers.
 *
 * A marker is only useful for the attempt that left it, so the guard bounds
 * itself two ways: {@see forgetAttempt()} drops an attempt's marker once the
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
     * De-dup key => the queue attempt that left it ("jobId:attempt"), or null
     * when the handler ran without an identifiable queue job.
     *
     * @var array<string, string|null>
     */
    protected array $failedJobs = [];

    public function markFailed(string $key, ?string $jobId = null, int $attempt = 1): void
    {
        $this->failedJobs[$key] = $jobId === null ? null : $this->attemptHandle($jobId, $attempt);

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
     * Discard whatever marker the given queue attempt left, without reporting it.
     */
    public function forgetAttempt(string $jobId, int $attempt): void
    {
        $handle = $this->attemptHandle($jobId, $attempt);

        foreach ($this->failedJobs as $key => $pending) {
            if ($pending === $handle) {
                unset($this->failedJobs[$key]);
            }
        }
    }

    public function pendingCount(): int
    {
        return count($this->failedJobs);
    }

    protected function attemptHandle(string $jobId, int $attempt): string
    {
        return $jobId.':'.$attempt;
    }
}
