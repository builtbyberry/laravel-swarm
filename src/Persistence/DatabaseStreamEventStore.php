<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Support\DatabaseTtl;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

class DatabaseStreamEventStore implements StreamEventStore
{
    use InteractsWithJsonColumns;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
    ) {}

    public function record(string $runId, SwarmStreamEvent $event, int $ttlSeconds): void
    {
        $timestamp = Carbon::now('UTC');

        $this->table()->insert([
            'run_id' => $runId,
            'event_type' => $event->type(),
            'payload' => $this->encodeJson($event->toArray()),
            'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function forget(string $runId): void
    {
        $this->table()->where('run_id', $runId)->delete();
    }

    public function events(string $runId): iterable
    {
        foreach ($this->table()->where('run_id', $runId)->orderBy('id')->cursor() as $record) {
            yield SwarmStreamEvent::fromArray($this->decodeJson($record->payload, []));
        }
    }

    protected function table(): Builder
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.stream_events', 'swarm_stream_events'));
    }
}
