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

    public function recordStep(string $runId, SwarmStep $step, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void;

    public function complete(string $runId, SwarmResponse $response, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void;

    public function fail(string $runId, Throwable $exception, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void;

    public function find(string $runId): ?array;

    /**
     * @param  array<string, mixed>|null  $contextSubset
     * @return iterable<array<string, mixed>>
     */
    public function findMatching(string $swarmClass, ?string $status, ?array $contextSubset): iterable;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function query(?string $swarmClass = null, ?string $status = null, int $limit = 25): array;
}
