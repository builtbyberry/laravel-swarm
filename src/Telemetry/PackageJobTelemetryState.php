<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Telemetry;

/**
 * Process-local guard for package job telemetry emitted inside job handlers.
 */
class PackageJobTelemetryState
{
    /**
     * @var array<string, true>
     */
    protected array $failedJobs = [];

    public function markFailed(string $key): void
    {
        $this->failedJobs[$key] = true;
    }

    public function consumeFailed(string $key): bool
    {
        if (! isset($this->failedJobs[$key])) {
            return false;
        }

        unset($this->failedJobs[$key]);

        return true;
    }
}
