<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

interface RoutesDurableWaits
{
    /**
     * @return array<int, array{name: string, timeout?: int|null, reason?: string|null, metadata?: array<string, mixed>}>
     */
    public function durableWaits(RunContext $context): array;
}
