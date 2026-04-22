<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

interface ContextStore
{
    public function put(RunContext $context, int $ttlSeconds): void;

    public function find(string $runId): ?array;
}
