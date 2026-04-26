<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use Throwable;

interface RecordsDurableRunFailureMetadata
{
    public function markFailedWithMetadata(string $runId, string $executionToken, Throwable $exception): void;
}
