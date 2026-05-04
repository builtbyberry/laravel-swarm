<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

// Stores and retrieves persisted swarm context by run identifier.
interface ContextStore
{
    public function put(RunContext $context, int $ttlSeconds): void;

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array;
}
