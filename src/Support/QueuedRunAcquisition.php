<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

final class QueuedRunAcquisition
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $executionToken = null,
    ) {}

    public static function fresh(string $executionToken): self
    {
        return new self('fresh', $executionToken);
    }

    public static function reclaimed(string $executionToken): self
    {
        return new self('reclaimed', $executionToken);
    }

    public static function duplicateRunning(): self
    {
        return new self('duplicate_running');
    }

    public static function duplicateCompleted(): self
    {
        return new self('duplicate_completed');
    }

    public static function duplicateFailed(): self
    {
        return new self('duplicate_failed');
    }

    public function acquired(): bool
    {
        return $this->outcome === 'fresh' || $this->outcome === 'reclaimed';
    }
}
