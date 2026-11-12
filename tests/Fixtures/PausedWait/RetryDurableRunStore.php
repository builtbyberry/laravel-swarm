<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\PausedWait;

use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\PausedSignal\HookedResumeDurableRunStore;
use Closure;

class RetryDurableRunStore extends HookedResumeDurableRunStore
{
    public int $attempts = 0;

    public ?Closure $beforeResume = null;

    public ?Closure $scan = null;

    public ?Closure $beforeCreate = null;

    public ?Closure $afterRelease = null;

    public function resume(string $runId): bool
    {
        $this->attempts++;
        if ($this->beforeResume !== null) {
            // Exercise the nested store/controller transaction boundary too.
            $this->connection->transaction(fn () => ($this->beforeResume)($runId, $this->attempts));
        }
        $this->afterResumeWaitScan = $this->scan;

        return parent::resume($runId);
    }

    public function createWait(string $runId, string $name, ?string $reason = null, ?int $timeoutSeconds = null, array $metadata = []): void
    {
        if ($this->beforeCreate !== null) {
            ($this->beforeCreate)($runId);
        }

        parent::createWait($runId, $name, $reason, $timeoutSeconds, $metadata);
    }

    public function releaseForNextStep(string $runId, string $executionToken, int $nextStepIndex): void
    {
        parent::releaseForNextStep($runId, $executionToken, $nextStepIndex);
        if ($this->afterRelease !== null) {
            ($this->afterRelease)($runId);
        }
    }
}
