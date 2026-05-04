<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamedSwarmResponse;
use Illuminate\Support\Collection;

class SwarmHistory
{
    public function __construct(
        protected RunHistoryStore $historyStore,
        protected StreamEventStore $streamEvents,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
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

    public function replay(string $runId): StreamableSwarmResponse
    {
        return new StreamableSwarmResponse(
            runId: $runId,
            generator: function () use ($runId): \Generator {
                $events = [];

                foreach ($this->streamEvents->events($runId) as $event) {
                    $events[] = $event;

                    yield $event;
                }

                if ($events === []) {
                    throw new SwarmException("No persisted stream replay events exist for run [{$runId}].");
                }

                return StreamedSwarmResponse::fromEvents($runId, new Collection($events));
            },
        );
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
