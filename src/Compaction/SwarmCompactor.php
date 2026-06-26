<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Compaction;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-run background compaction coordinator (#287).
 *
 * Graduates a sealed event prefix from the hot event log (swarm_stream_events) to cold
 * storage (swarm_cold_archives) under a per-run compaction lease. The ordering spine is:
 *
 *   cold-durable → sealed_at UPDATE → CAS base-pointer advance → DELETE from hot
 *
 * This spine is enforced inside DatabaseColdArchiveDriver::graduate(). reclaim() is called
 * only after graduate() returns true (CAS succeeded), so a crash at any point leaves the
 * hot log intact. A Throwable during graduation quarantines the run so it is not
 * retry-looped into a crash cycle.
 *
 * Stateless singleton-safe: no mutable per-run instance fields.
 *
 * @internal
 */
class SwarmCompactor
{
    public function __construct(
        protected DatabaseColdArchiveDriver $cold,
        protected DatabaseCausalLogStore $causalLog,
        protected SwarmPersistenceCipher $cipher,
        protected Connection $connection,
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
        protected SwarmAuditDispatcher $audit,
    ) {}

    /**
     * Compact a single run. Idempotent: calling on an already-graduated run is a no-op.
     *
     * Returns true when graduation succeeded, false when skipped (no barrier, already
     * graduated, or lease contention). The caller should not retry on false — the
     * scheduler will dispatch a fresh job next cycle.
     */
    public function compact(string $runId): bool
    {
        $token = $this->acquireLease($runId);

        if ($token === null) {
            return false;
        }

        try {
            return $this->doCompact($runId);
        } catch (Throwable $exception) {
            $this->quarantine($runId, $exception);

            return false;
        } finally {
            $this->releaseLease($runId, $token);
        }
    }

    /**
     * @throws Throwable — re-thrown to trigger quarantine in compact()
     */
    protected function doCompact(string $runId): bool
    {
        $hotTable = (string) $this->config->get('swarm.tables.stream_events', 'swarm_stream_events');

        // Current base pointer: events with id < base are already in cold.
        $currentBase = $this->cold->basePointer($runId);

        // Find the first seal barrier that has not yet been graduated. Ascending order
        // ensures we process one sealed window at a time: if multiple barriers exist
        // (e.g. the run was compacted before), the next un-graduated one is always
        // the lowest id > currentBase. The snapshot covers exactly [currentBase, barrier)
        // so the snapshot and the cold event rows always agree on the same range.
        $barrier = $this->connection->table($hotTable)
            ->where('run_id', $runId)
            ->where('event_type', 'swarm_causal_seal_barrier')
            ->where('id', '>', $currentBase)
            ->orderBy('id')
            ->first(['id']);

        if ($barrier === null) {
            return false;
        }

        $barrierDbId = (int) $barrier->id;

        // Read events [currentBase, barrierDbId) to build the fold snapshot.
        $events = $this->causalLog->eventsFrom($runId, $currentBase);
        $view = new CausalLogView($this->boundedEvents($events));
        $sealedSnapshot = $this->cipher->seal(json_encode($view->snapshot(), JSON_THROW_ON_ERROR))
            ?? throw new \LogicException('Sealed snapshot is unexpectedly null — cipher::seal() contract violated.');

        // Graduate to cold (cold-durable + sealed_at UPDATE + CAS in one transaction).
        $graduated = $this->cold->graduate(
            runId: $runId,
            fromId: $currentBase,
            boundaryId: $barrierDbId,
            sealedSnapshot: $sealedSnapshot,
        );

        if (! $graduated) {
            // CAS race — another compactor won; hot is still intact.
            return false;
        }

        // Reclaim: delete graduated events from hot only after CAS succeeded.
        $this->cold->reclaim($runId, $barrierDbId);

        return true;
    }

    /**
     * Yields content events from the causal log, stopping (exclusively) at the first
     * `swarm_causal_seal_barrier` event encountered.
     *
     * eventsFrom() yields in ascending DB-id order. The barrier query in doCompact()
     * also uses ascending order and a `> $currentBase` predicate, so the first barrier
     * encountered here IS the barrier whose DB id was chosen as $barrierDbId. Stopping
     * at it (exclusive) gives us exactly the event set [currentBase, barrierDbId) —
     * the same range graduate() writes to cold storage.
     *
     * @param  iterable<\BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent>  $events
     * @return iterable<\BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent>
     */
    protected function boundedEvents(iterable $events): iterable
    {
        foreach ($events as $event) {
            if (($event->toArray()['type'] ?? null) === 'swarm_causal_seal_barrier') {
                return;
            }

            yield $event;
        }
    }

    /**
     * Acquires the per-run compaction lease via a CAS UPDATE.
     * Returns a token string on success, null when the lease is held or the run is quarantined.
     */
    protected function acquireLease(string $runId): ?string
    {
        $durableTable = (string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs');
        $leaseSeconds = (int) $this->config->get('swarm.compaction.lease_seconds', 300);
        $token = (string) \Illuminate\Support\Str::uuid();
        $now = Carbon::now('UTC');

        $acquired = $this->connection->table($durableTable)
            ->where('run_id', $runId)
            ->where(function ($q) use ($now): void {
                $q->whereNull('compaction_leased_until')
                    ->orWhere('compaction_leased_until', '<', $now);
            })
            ->whereNull('compaction_quarantined_at')
            ->update([
                'compaction_token' => $token,
                'compaction_leased_until' => $now->copy()->addSeconds($leaseSeconds),
            ]);

        return $acquired === 1 ? $token : null;
    }

    protected function releaseLease(string $runId, string $token): void
    {
        $durableTable = (string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs');

        try {
            $this->connection->table($durableTable)
                ->where('run_id', $runId)
                ->where('compaction_token', $token)
                ->update([
                    'compaction_token' => null,
                    'compaction_leased_until' => null,
                ]);
        } catch (Throwable) {
            // Release failure is non-fatal; the lease TTL will expire naturally.
        }
    }

    protected function quarantine(string $runId, Throwable $exception): void
    {
        $durableTable = (string) $this->config->get('swarm.tables.durable', 'swarm_durable_runs');

        $this->logger->warning('laravel-swarm: compaction failed — run quarantined to prevent crash loop.', [
            'run_id' => $runId,
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        try {
            $this->connection->table($durableTable)
                ->where('run_id', $runId)
                ->update(['compaction_quarantined_at' => Carbon::now('UTC')]);

            try {
                $this->audit->emit('compaction.quarantined', [
                    'run_id' => $runId,
                    'exception_class' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            } catch (Throwable) {
                // Audit emit failure must not suppress the quarantine DB write.
            }
        } catch (Throwable $quarantineException) {
            $this->logger->error('laravel-swarm: failed to quarantine run after compaction failure.', [
                'run_id' => $runId,
                'exception_class' => $quarantineException::class,
            ]);
        }
    }
}
