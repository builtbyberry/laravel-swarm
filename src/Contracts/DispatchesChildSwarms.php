<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

interface DispatchesChildSwarms
{
    /**
     * @return array<int, array{swarm: class-string<Swarm>, task: string|array<string, mixed>|RunContext}>
     */
    public function durableChildSwarms(RunContext $context): array;
}
