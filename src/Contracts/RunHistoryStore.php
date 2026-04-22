<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Throwable;

// Stores and retrieves swarm run history, including steps and completion state.
interface RunHistoryStore
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function start(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, int $ttlSeconds): void;

    public function recordStep(string $runId, SwarmStep $step, int $ttlSeconds): void;

    public function complete(string $runId, SwarmResponse $response, int $ttlSeconds): void;

    public function fail(string $runId, Throwable $exception, int $ttlSeconds): void;

    public function find(string $runId): ?array;
}
