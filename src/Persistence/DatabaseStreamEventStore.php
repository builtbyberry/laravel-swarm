<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmUnknownEvent;
use BuiltByBerry\LaravelSwarm\Support\DatabaseTtl;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * @internal
 */
class DatabaseStreamEventStore implements StreamEventStore
{
    use InteractsWithJsonColumns;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected StreamEventPayloadCodec $payloads,
    ) {}

    public function record(string $runId, SwarmStreamEvent $event, int $ttlSeconds): void
    {
        $timestamp = Carbon::now('UTC');

        $this->table()->insert([
            'run_id' => $runId,
            'event_type' => $event->type(),
            'payload' => $this->encodeJson($this->payloads->sealPayload($event->toArray() + ['attempt_epoch' => $event->attemptEpoch])),
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
        foreach ($this->table()->where('run_id', $runId)->where('event_type', '!=', 'swarm_causal_seal_barrier')->orderBy('id')->cursor() as $record) {
            $event = SwarmStreamEvent::fromArray($this->payloads->openPayload($this->decodeJson($record->payload, [])));
            if (! ($event instanceof SwarmUnknownEvent)) {
                yield $this->withAttemptEpoch($event, $record->attempt_epoch ?? null);
            }
        }
    }

    /**
     * Restore the durable attempt epoch (#298) from its queryable column onto the
     * event object. The hot column is authoritative over the storage envelope;
     * null means a non-durable event and clears any payload epoch.
     */
    private function withAttemptEpoch(SwarmStreamEvent $event, mixed $epoch): SwarmStreamEvent
    {
        if (is_int($epoch)) {
            return $event->withAttemptEpoch($epoch);
        }

        if (is_string($epoch) && ctype_digit($epoch)) {
            return $event->withAttemptEpoch((int) $epoch);
        }

        $event->attemptEpoch = null;

        return $event;
    }

    public function assertReady(): void
    {
        $table = (string) $this->config->get('swarm.tables.stream_events', 'swarm_stream_events');
        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            throw new SwarmException("Database-backed stream replay requires the [{$table}] table.");
        }

        if (! $schema->hasColumns($table, ['id', 'run_id', 'event_type', 'payload', 'created_at', 'updated_at', 'expires_at'])) {
            throw new SwarmException("Database-backed stream replay requires runtime columns on [{$table}] for persisted stream events.");
        }
    }

    /**
     * Yields events for `$runId` with DB id >= `$fromSequence`, in ascending order.
     *
     * Used by the hot half of {@see TieredStreamEventStore}: after the cold tier
     * has yielded events with id < base, the hot store yields id >= base so the
     * two halves together cover the full set without gap or overlap (half-open seam,
     * F1 invariant from #286).
     *
     * @return iterable<int, SwarmStreamEvent>
     */
    public function eventsFrom(string $runId, int $fromSequence): iterable
    {
        foreach ($this->table()->where('run_id', $runId)->where('id', '>=', $fromSequence)->orderBy('id')->cursor() as $record) {
            $event = SwarmStreamEvent::fromArray($this->payloads->openPayload($this->decodeJson($record->payload, [])));
            if (! ($event instanceof SwarmUnknownEvent)) {
                yield $this->withAttemptEpoch($event, $record->attempt_epoch ?? null);
            }
        }
    }

    protected function table(): Builder
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.stream_events', 'swarm_stream_events'));
    }
}
