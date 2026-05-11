<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Throwable;

/**
 * Minimal {@see RunHistoryStore} for tests that only need {@see find()} behavior.
 */
final class ConfigurableFindRunHistoryStore implements RunHistoryStore
{
    /** @var array<string, mixed>|null */
    public ?array $findResult = null;

    public function start(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, int $ttlSeconds): void {}

    public function recordStep(string $runId, SwarmStep $step, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void {}

    public function complete(string $runId, SwarmResponse $response, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void {}

    public function fail(string $runId, Throwable $exception, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void {}

    public function recordPreflightFailure(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, Throwable $exception, int $ttlSeconds): void {}

    public function find(string $runId): ?array
    {
        return $this->findResult;
    }

    public function findMatching(string $swarmClass, ?string $status, ?array $contextSubset): iterable
    {
        return [];
    }

    public function query(?string $swarmClass = null, ?string $status = null, int $limit = 25): array
    {
        return [];
    }
}
