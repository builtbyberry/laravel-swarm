<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;

class SwarmHistory
{
    public function __construct(
        protected RunHistoryStore $historyStore,
    ) {}

    public function find(string $runId): ?array
    {
        return $this->historyStore->find($runId);
    }

    /**
     * @param  array<string, mixed>|null  $contextSubset
     * @return iterable<array<string, mixed>>
     */
    public function findMatching(string $swarmClass, ?string $status = null, ?array $contextSubset = null): iterable
    {
        return $this->historyStore->findMatching($swarmClass, $status, $contextSubset);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 25): array
    {
        return $this->historyStore->query(limit: $limit);
    }

    public function forSwarm(string $swarmClass): SwarmHistoryQuery
    {
        return new SwarmHistoryQuery($this->historyStore, swarmClass: $swarmClass);
    }

    public function withStatus(string $status): SwarmHistoryQuery
    {
        return new SwarmHistoryQuery($this->historyStore, status: $status);
    }
}
