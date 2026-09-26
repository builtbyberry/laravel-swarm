<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Parallel;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use LogicException;
use Throwable;

/**
 * Mapping-only stores for a branch process. The parent process is the sole
 * persistence owner; any child write is a programming error.
 *
 * @internal
 */
final class DetachedParallelStreamStores implements ArtifactRepository, ContextStore, RunHistoryStore
{
    public function put(RunContext $context, int $ttlSeconds): void
    {
        $this->unexpected();
    }

    public function find(string $runId): ?array
    {
        $this->unexpected();
    }

    public function storeMany(string $runId, array $artifacts, int $ttlSeconds): void
    {
        $this->unexpected();
    }

    public function all(string $runId): array
    {
        $this->unexpected();
    }

    public function start(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, int $ttlSeconds): void
    {
        $this->unexpected();
    }

    public function recordStep(string $runId, SwarmStep $step, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $this->unexpected();
    }

    public function complete(string $runId, SwarmResponse $response, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $this->unexpected();
    }

    public function fail(string $runId, Throwable $exception, int $ttlSeconds, ?string $executionToken = null, ?int $leaseSeconds = null): void
    {
        $this->unexpected();
    }

    public function recordPreflightFailure(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, Throwable $exception, int $ttlSeconds): void
    {
        $this->unexpected();
    }

    public function findMatching(string $swarmClass, ?string $status, ?array $contextSubset): iterable
    {
        $this->unexpected();
    }

    public function query(?string $swarmClass = null, ?string $status = null, int $limit = 25): array
    {
        $this->unexpected();
    }

    private function unexpected(): never
    {
        throw new LogicException('Parallel stream branch processes cannot read or write parent-owned persistence stores.');
    }
}
