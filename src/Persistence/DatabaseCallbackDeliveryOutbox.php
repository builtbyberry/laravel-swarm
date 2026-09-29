<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableCallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Enums\CallbackSlot;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\DeliverSwarmCallback;
use BuiltByBerry\LaravelSwarm\Responses\CallbackDrainResult;
use BuiltByBerry\LaravelSwarm\Responses\SwarmTerminalContext;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Laravel\SerializableClosure\SerializableClosure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Database-backed delivery buffer for terminal workflow then()/catch() callbacks.
 *
 * A registered callback is stored as a signed, sealed SerializableClosure. The
 * signature (HMAC over APP_KEY, applied by SerializableClosure and verified on
 * unserialize) is the payload-authorization boundary: a tampered row can never be
 * invoked, it is dead-lettered. The closure body is additionally sealed at rest by
 * {@see SwarmPersistenceCipher} and never leaves this table — only a row id travels
 * to the {@see DeliverSwarmCallback} delivery job.
 *
 * Delivery is at-least-once, never exactly-once: a crash after the closure runs but
 * before its row delete re-delivers on the next drain. Registered/pending rows are
 * re-claimable after the reservation timeout (shared with the durable relay,
 * swarm.durable.relay.reservation_timeout_seconds). Rows that exceed
 * swarm.callbacks.max_attempts move to 'dead_letter' and stop being re-claimed.
 *
 * @internal
 */
class DatabaseCallbackDeliveryOutbox implements CallbackDeliveryOutbox, ReadableCallbackDeliveryOutbox
{
    use SafeReporting;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected SwarmPersistenceCipher $cipher,
        protected BusDispatcher $bus,
        protected ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger;
    }

    public function register(string $runId, CallbackSlot $slot, Closure $callback): void
    {
        if (! $this->isAvailable()) {
            throw new SwarmException(
                'Terminal workflow callbacks require database-backed persistence. Set '
                .'swarm.persistence.driver to "database" and run the package migrations, or listen '
                .'to the SwarmCompleted / SwarmFailed events instead of then()/catch().'
            );
        }

        try {
            $serialized = serialize(new SerializableClosure($callback));
        } catch (Throwable $exception) {
            // A closure that captures a non-serializable binding (a PDO handle, a
            // resource, a live model with an open connection) cannot be delivered
            // later in another process. Fail loud at registration rather than
            // silently dropping the guarantee.
            throw new SwarmException(
                'A terminal workflow callback must be serializable so it can be delivered after the '
                .'workflow settles, in a separate process. Avoid capturing non-serializable bindings '
                .'(database handles, open resources) in the closure. ('.$exception->getMessage().')',
                previous: $exception,
            );
        }

        $now = Carbon::now('UTC');

        $this->table()->insert([
            'run_id' => $runId,
            'slot' => $slot->value,
            'callback' => $this->cipher->seal($serialized),
            'context' => null,
            'attempts' => 0,
            'status' => 'registered',
            'last_error' => null,
            'last_attempted_at' => null,
            'reserved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function settle(string $runId, SwarmTerminalContext $context): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $outcome = $context->slot;
        $other = $outcome === CallbackSlot::Then ? CallbackSlot::Catch : CallbackSlot::Then;
        $now = Carbon::now('UTC');

        // The non-firing outcome never runs: a completed run drops its catch
        // callbacks, a failed run drops its then callbacks.
        $this->table()
            ->where('run_id', $runId)
            ->where('slot', $other->value)
            ->delete();

        // Flip only 'registered' rows: a duplicated terminal event re-enters here,
        // finds the surviving rows already 'pending', and flips nothing — so a
        // callback is queued for delivery at most once per settle, never re-armed.
        $this->table()
            ->where('run_id', $runId)
            ->where('slot', $outcome->value)
            ->where('status', 'registered')
            ->update([
                'status' => 'pending',
                'context' => $this->cipher->seal($this->encodeContext($context)),
                'updated_at' => $now,
            ]);
    }

    public function discard(string $runId): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $this->table()->where('run_id', $runId)->delete();
    }

    public function drain(int $limit = 100): CallbackDrainResult
    {
        if ($limit < 1) {
            return new CallbackDrainResult(0, 0, 0, 0, 0);
        }

        $reservationTimeoutSeconds = (int) $this->config->get('swarm.durable.relay.reservation_timeout_seconds', 60);
        $now = Carbon::now('UTC');
        $staleThreshold = $now->copy()->subSeconds($reservationTimeoutSeconds);

        $entries = $this->connection->transaction(function () use ($now, $staleThreshold, $limit) {
            $query = $this->table()
                ->where('status', 'pending')
                ->where(function ($q) use ($staleThreshold): void {
                    $q->whereNull('reserved_at')
                        ->orWhere('reserved_at', '<', $staleThreshold);
                })
                ->orderBy('id')
                ->limit($limit);

            if ($this->connection->getDriverName() !== 'sqlite') {
                $query->lock('for update skip locked');
            }

            $entries = $query->get();

            if ($entries->isEmpty()) {
                return $entries;
            }

            $this->table()->whereIn('id', $entries->pluck('id')->all())->update(['reserved_at' => $now]);

            return $entries;
        });

        if ($entries->isEmpty()) {
            return new CallbackDrainResult(0, 0, 0, 0, 0);
        }

        $claimed = $entries->count();
        $reclaimed = $entries->filter(fn (object $e): bool => $e->reserved_at !== null)->count();

        $dispatched = 0;
        $failed = 0;

        foreach ($entries as $entry) {
            try {
                // Only the row id travels to the delivery job; the sealed closure
                // stays at rest in this table (never in a queue payload).
                $this->bus->dispatch(new DeliverSwarmCallback((int) $entry->id));
                $dispatched++;
            } catch (Throwable $exception) {
                // Transient dispatch failure (queue driver unavailable). Leave the
                // reservation in place; the row is re-claimable after the timeout.
                $this->safeReport($exception);
                $failed++;
            }
        }

        return new CallbackDrainResult($dispatched, 0, $failed, $claimed, $reclaimed);
    }

    public function deliver(int $id): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        /** @var object|null $row */
        $row = $this->table()->where('id', $id)->first();

        // Idempotent: a missing row (already delivered) or one not in the pending
        // delivery state is a no-op — a duplicate dispatch never double-arms it.
        if ($row === null || $row->status !== 'pending') {
            return;
        }

        $maxAttempts = max(1, (int) $this->config->get('swarm.callbacks.max_attempts', 5));
        $attempts = (int) $row->attempts + 1;

        $closure = $this->resolveClosure($row);

        if ($closure === null) {
            // A closure that cannot be unsealed or whose signature does not verify
            // can never be invoked. Dead-letter it permanently rather than burning
            // the attempt budget on a deterministic failure.
            $this->deadLetter($id, $attempts, 'callback signature invalid or unreadable');

            return;
        }

        $context = $this->resolveContext($row);

        try {
            $closure($context);
        } catch (Throwable $exception) {
            // The callback ran in this delivery process, entirely separate from the
            // settled workflow: its failure can neither replay the workflow's model
            // or tool effects nor change the already-recorded terminal result. It
            // only affects this delivery row.
            $this->safeReport($exception);
            $error = mb_substr($exception->getMessage(), 0, 1000);

            if ($attempts >= $maxAttempts) {
                $this->deadLetter($id, $attempts, $error);
            } else {
                $this->releaseForRetry($id, $attempts, $error);
            }

            return;
        }

        $this->table()->where('id', $id)->delete();
    }

    protected function resolveClosure(object $row): ?Closure
    {
        try {
            $raw = is_string($row->callback) ? $this->cipher->openStrict($row->callback) : null;

            if (! is_string($raw)) {
                return null;
            }

            $restored = unserialize($raw);

            if (! $restored instanceof SerializableClosure) {
                return null;
            }

            return $restored->getClosure();
        } catch (Throwable $exception) {
            // Includes InvalidSignatureException (tampered payload) and decrypt
            // failures — both permanent, both routed to dead-letter by the caller.
            $this->safeReport($exception);

            return null;
        }
    }

    protected function resolveContext(object $row): SwarmTerminalContext
    {
        $slot = CallbackSlot::tryFrom((string) $row->slot) ?? CallbackSlot::Then;
        $fallback = new SwarmTerminalContext((string) $row->run_id, $slot, 'unknown');

        if (! is_string($row->context) || $row->context === '') {
            return $fallback;
        }

        try {
            $raw = $this->cipher->open($row->context);
            $decoded = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            return $fallback;
        }

        if (! is_array($decoded)) {
            return $fallback;
        }

        return new SwarmTerminalContext(
            runId: is_string($decoded['run_id'] ?? null) ? $decoded['run_id'] : (string) $row->run_id,
            slot: CallbackSlot::tryFrom((string) ($decoded['slot'] ?? $slot->value)) ?? $slot,
            swarmClass: is_string($decoded['swarm_class'] ?? null) ? $decoded['swarm_class'] : 'unknown',
            topology: is_string($decoded['topology'] ?? null) ? $decoded['topology'] : null,
            executionMode: is_string($decoded['execution_mode'] ?? null) ? $decoded['execution_mode'] : null,
            exceptionClass: is_string($decoded['exception_class'] ?? null) ? $decoded['exception_class'] : null,
            exceptionMessage: is_string($decoded['exception_message'] ?? null) ? $decoded['exception_message'] : null,
        );
    }

    protected function encodeContext(SwarmTerminalContext $context): string
    {
        return json_encode($context->toArray(), JSON_THROW_ON_ERROR);
    }

    protected function releaseForRetry(int $id, int $attempts, string $error): void
    {
        $this->table()->where('id', $id)->update([
            'attempts' => $attempts,
            'last_error' => $this->cipher->seal($error),
            'last_attempted_at' => Carbon::now('UTC'),
            'reserved_at' => null,
            'updated_at' => Carbon::now('UTC'),
        ]);
    }

    protected function deadLetter(int $id, int $attempts, string $error): void
    {
        $now = Carbon::now('UTC');

        $this->table()->where('id', $id)->update([
            'status' => 'dead_letter',
            'attempts' => $attempts,
            'last_error' => $this->cipher->seal($error),
            'last_attempted_at' => $now,
            'reserved_at' => null,
            'updated_at' => $now,
        ]);

        $this->safeLog($this->logger, 'error', 'Swarm terminal callback reached dead_letter status.', [
            'id' => $id,
            'attempts' => $attempts,
            'last_error' => $error,
        ]);
    }

    public function isAvailable(): bool
    {
        if ($this->config->get('swarm.persistence.driver') !== 'database') {
            return false;
        }

        try {
            return $this->connection->getSchemaBuilder()->hasTable($this->tableName());
        } catch (Throwable) {
            return false;
        }
    }

    public function assertReady(): void
    {
        if (! $this->isAvailable()) {
            throw new SwarmException(
                'Callback delivery outbox is not available. The swarm_callback_deliveries table is '
                .'required for terminal workflow then()/catch() callbacks (swarm.callbacks.enabled=true). '
                .'Run the package migrations or disable the feature.'
            );
        }
    }

    public function pending(int $limit = 100): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return $this->table()
            ->whereIn('status', ['registered', 'pending'])
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (object $record): array => $this->mapRowSummary($record))
            ->all();
    }

    public function deadLettered(int $limit = 100): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return $this->table()
            ->where('status', 'dead_letter')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (object $record): array => $this->mapRowSummary($record))
            ->all();
    }

    public function healthSummary(): array
    {
        if (! $this->isAvailable()) {
            return [
                'available' => false,
                'registered' => 0,
                'pending' => 0,
                'dead_letter' => 0,
                'reserved' => 0,
                'oldest_pending_at' => null,
            ];
        }

        $reservationTimeoutSeconds = (int) $this->config->get('swarm.durable.relay.reservation_timeout_seconds', 60);
        $freshThreshold = Carbon::now('UTC')->subSeconds($reservationTimeoutSeconds);

        $registered = (int) $this->table()->where('status', 'registered')->count();
        $pending = (int) $this->table()->where('status', 'pending')->count();
        $deadLetter = (int) $this->table()->where('status', 'dead_letter')->count();
        $reserved = (int) $this->table()
            ->where('status', 'pending')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '>=', $freshThreshold)
            ->count();
        $oldestPendingAt = $this->table()->where('status', 'pending')->min('created_at');

        return [
            'available' => true,
            'registered' => $registered,
            'pending' => $pending,
            'dead_letter' => $deadLetter,
            'reserved' => $reserved,
            'oldest_pending_at' => $oldestPendingAt !== null ? (string) $oldestPendingAt : null,
        ];
    }

    /**
     * Row metadata + display-decrypted `last_error` — NEVER the sealed closure or context.
     *
     * @return array<string, mixed>
     */
    protected function mapRowSummary(object $record): array
    {
        [$lastError, $lastErrorAvailable] = $this->cipher->openForDisplay(
            $record->last_error === null ? null : (string) $record->last_error,
        );

        return [
            'id' => (int) $record->id,
            'run_id' => $record->run_id,
            'slot' => $record->slot,
            'status' => $record->status,
            'attempts' => (int) $record->attempts,
            'last_error' => $lastError,
            'last_error_available' => $lastErrorAvailable,
            'reserved_at' => $record->reserved_at ?? null,
            'last_attempted_at' => $record->last_attempted_at ?? null,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    protected function table(): Builder
    {
        return $this->connection->table($this->tableName());
    }

    protected function tableName(): string
    {
        return (string) $this->config->get('swarm.tables.callback_deliveries', 'swarm_callback_deliveries');
    }
}
