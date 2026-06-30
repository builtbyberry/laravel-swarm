<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SealedCausalWindowException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnknownCausalTargetException;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalSealBarrier;
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
            // Promoted from the JSON payload (#284) / event object (#298) into
            // queryable columns so the durable resume-time void lookup can select a
            // node's prior-attempt events without unpacking JSON. node_id is null
            // for a top-level event; attempt_epoch is null for any non-durable-
            // streamed event (only a durable advancer stamps it).
            'node_id' => $event->nodeId,
            'attempt_epoch' => $event->attemptEpoch,
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
        ?string $digestNodeId = null,
    ): void {
        // Fail loud with a clear message on the cache driver / un-migrated schema,
        // rather than letting a raw QueryException surface from the insert.
        $this->assertReady();

        $this->connection->transaction(function () use ($runId, $type, $targetEventId, $reason, $ttlSeconds, $digestNodeId): void {
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
                digestNodeId: $digestNodeId,
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

    public function sealRollup(
        string $runId,
        array $targetEventIds,
        string $digestNodeId,
        string $reason,
        int $ttlSeconds = 0,
    ): void {
        $this->assertReady();

        $this->connection->transaction(function () use ($runId, $targetEventIds, $digestNodeId, $reason, $ttlSeconds): void {
            $emittedAny = false;

            foreach ($targetEventIds as $targetEventId) {
                // Idempotent: a target already carrying a rolled_up edge (a
                // re-dispatched/re-executed pass) is skipped rather than voided
                // twice. The dedicated void columns make this an indexed lookup.
                $alreadyRolledUp = $this->table()
                    ->where('run_id', $runId)
                    ->where('void_type', CausalVoidEdgeType::RolledUp->value)
                    ->where('void_target_event_uuid', $targetEventId)
                    ->exists();

                if ($alreadyRolledUp) {
                    continue;
                }

                // appendVoidEdge fences the target row and throws on a sealed
                // target; nesting it here runs under a savepoint, so any throw
                // rolls back the whole rollup-seal — never a partial.
                $this->appendVoidEdge($runId, CausalVoidEdgeType::RolledUp, $targetEventId, $reason, $ttlSeconds, $digestNodeId);
                $emittedAny = true;
            }

            // Barrier last, and only when this call actually voided something.
            // Edges + barrier are written in one transaction, so an all-skipped
            // call (every target already rolled up) means the barrier from the
            // original call already exists — re-recording it would graduate an
            // empty window. The compactor keys on the barrier, so emitting it
            // only after at least one fresh edge keeps a partial rollup
            // ungraduatable while never duplicating a window.
            if ($emittedAny) {
                $this->record($runId, new SwarmCausalSealBarrier(
                    id: SwarmStreamEvent::newId(),
                    runId: $runId,
                    timestamp: SwarmStreamEvent::timestamp(),
                ), $ttlSeconds);
            }
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

    public function voidNodeAttempt(
        string $runId,
        string $nodeId,
        int $epoch,
        string $reason,
        int $ttlSeconds = 0,
    ): void {
        $this->assertReady();

        // Metadata-only (#298 F6): the prior attempt's first event for this
        // node+epoch, via the queryable columns — never decrypting payload.
        $firstEventUuid = $this->table()
            ->where('run_id', $runId)
            ->where('node_id', $nodeId)
            ->where('attempt_epoch', $epoch)
            ->orderBy('id')
            ->value('event_uuid');

        // The prior attempt streamed nothing (crashed before its first event) —
        // there is nothing to retract.
        if (! is_string($firstEventUuid)) {
            return;
        }

        // Idempotent (#298 F3): a redelivered/repeated resume that already wrote
        // the retraction is a no-op, never a second edge. Durable resumes are
        // lease-fenced, so this guards at-least-once redelivery, not concurrency.
        $alreadyVoided = $this->table()
            ->where('run_id', $runId)
            ->where('void_type', CausalVoidEdgeType::NodeReexecuted->value)
            ->where('void_target_event_uuid', $firstEventUuid)
            ->exists();

        if ($alreadyVoided) {
            return;
        }

        // One edge retracts the whole (node_id, epoch) membership; the fold reads
        // the retracted pair off this target event. Throws loud on a sealed target
        // (the seal-follows-commit invariant means that should never happen for an
        // uncommitted node).
        $this->appendVoidEdge($runId, CausalVoidEdgeType::NodeReexecuted, $firstEventUuid, $reason, $ttlSeconds);
    }

    public function assertReady(): void
    {
        parent::assertReady();

        $table = (string) $this->config->get('swarm.tables.stream_events', 'swarm_stream_events');
        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasColumns($table, ['event_uuid', 'void_type', 'void_target_event_uuid', 'void_reason', 'sealed_at', 'node_id', 'attempt_epoch'])) {
            throw new SwarmException(
                "Causal-log void-edges require the void-edge and durable-streaming columns on [{$table}]; run the package migrations under the [database] persistence driver.",
            );
        }
    }
}
