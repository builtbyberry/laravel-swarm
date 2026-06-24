<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SealedCausalWindowException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnknownCausalTargetException;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalVoidEdge;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Support\DatabaseTtl;
use Illuminate\Support\Carbon;

/**
 * Database-backed append-only causal log (#282).
 *
 * Extends {@see DatabaseStreamEventStore} so all existing stream-replay behavior
 * is inherited unchanged, and adds the void-edge surface: an event's own UUID is
 * promoted to the queryable `event_uuid` column on every write, and a void-edge
 * is appended (never a deletion) against a still-unsealed target under a row
 * lock so a concurrent seal can never slip past the guard.
 *
 * @internal
 */
class DatabaseCausalLogStore extends DatabaseStreamEventStore implements CausalLogStore
{
    /**
     * Append an event, promoting its own UUID to the indexed `event_uuid` column.
     *
     * The parent's insert omits `event_uuid`; without it a void-edge could not
     * locate its target, and `isSealed()` could not address an event. We extract
     * the id from the serialized payload (every {@see SwarmStreamEvent} emits one)
     * rather than a typed property so this holds for any event shape.
     */
    public function record(string $runId, SwarmStreamEvent $event, int $ttlSeconds): void
    {
        $payload = $event->toArray();
        $timestamp = Carbon::now('UTC');

        $this->table()->insert([
            'run_id' => $runId,
            'event_uuid' => is_string($payload['id'] ?? null) ? $payload['id'] : null,
            'event_type' => $event->type(),
            'payload' => $this->encodeJson($payload),
            'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function appendVoidEdge(
        string $runId,
        CausalVoidEdgeType $type,
        string $targetEventId,
        string $reason,
        int $ttlSeconds = 0,
    ): void {
        // Fail loud with a clear message on the cache driver / un-migrated schema,
        // rather than letting a raw QueryException surface from the insert.
        $this->assertReady();

        $this->connection->transaction(function () use ($runId, $type, $targetEventId, $reason, $ttlSeconds): void {
            // Fence the target row: a concurrent #287 seal must take the same lock,
            // so it either commits before this SELECT (we observe sealed_at and
            // throw) or blocks until this void-edge commits. No TOCTOU window.
            $target = $this->table()
                ->where('run_id', $runId)
                ->where('event_uuid', $targetEventId)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                throw new UnknownCausalTargetException(
                    "Cannot void-edge unknown causal target [{$targetEventId}] in run [{$runId}].",
                );
            }

            if ($target->sealed_at !== null) {
                throw new SealedCausalWindowException(
                    "Cannot void-edge sealed causal target [{$targetEventId}] in run [{$runId}]; sealed history is not retractable.",
                );
            }

            $edge = new SwarmCausalVoidEdge(
                id: SwarmStreamEvent::newId(),
                runId: $runId,
                voidType: $type,
                targetEventId: $targetEventId,
                reason: $reason,
                timestamp: SwarmStreamEvent::timestamp(),
            );

            $timestamp = Carbon::now('UTC');

            $this->table()->insert([
                'run_id' => $runId,
                'event_uuid' => $edge->id,
                'event_type' => $edge->type(),
                'payload' => $this->encodeJson($edge->toArray()),
                'void_type' => $type->value,
                'void_target_event_uuid' => $targetEventId,
                'void_reason' => $reason,
                'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        });
    }

    public function isSealed(string $runId, string $eventUuid): bool
    {
        return $this->table()
            ->where('run_id', $runId)
            ->where('event_uuid', $eventUuid)
            ->whereNotNull('sealed_at')
            ->exists();
    }

    public function assertReady(): void
    {
        parent::assertReady();

        $table = (string) $this->config->get('swarm.tables.stream_events', 'swarm_stream_events');
        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasColumns($table, ['event_uuid', 'void_type', 'void_target_event_uuid', 'void_reason', 'sealed_at'])) {
            throw new SwarmException(
                "Causal-log void-edges require the void-edge columns on [{$table}]; run the package migrations under the [database] persistence driver.",
            );
        }
    }
}
