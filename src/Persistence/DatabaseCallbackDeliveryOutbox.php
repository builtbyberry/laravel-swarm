<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
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
use Laravel\SerializableClosure\Exceptions\InvalidSignatureException;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Signed;
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
 * before its row delete re-delivers after the lease expires. Each claim has an opaque
 * token; only that token can acquire pending → delivering and count an attempt. Failed
 * dispatches/executions release with backoff. Eligible work already at the current
 * attempt cap, or a final allowed callback failure, moves to 'dead_letter'.
 *
 * @internal
 */
class DatabaseCallbackDeliveryOutbox implements CallbackDeliveryOutbox, ReadableCallbackDeliveryOutbox
{
    use SafeReporting;

    protected ?bool $readiness = null;

    protected ?string $readinessFailure = null;

    protected ?bool $settlementAvailability = null;

    /**
     * The only classes a stored callback may deserialize into: the closure wrapper
     * and its signed body ({@see Signed}). Those wrappers may be restored so the
     * signature can be verified; foreign payload objects and unsigned closure bodies
     * are never constructed or invoked.
     */
    protected const CLOSURE_CLASSES = [SerializableClosure::class, Signed::class];

    protected const NO_SIGNING_KEY_REASON = 'callback is unsigned or cannot be verified: no APP_KEY signing key is configured (terminal callbacks require APP_KEY)';

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected SwarmPersistenceCipher $cipher,
        protected BusDispatcher $bus,
        protected SwarmAuditDispatcher $audit,
        protected ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger;
    }

    public function register(string $runId, CallbackSlot $slot, Closure $callback): void
    {
        $this->assertReady();

        // With no signing key SerializableClosure stores an unsigned body, which
        // delivery refuses to deserialize: the row could only ever dead-letter. Fail
        // loud here so the caller learns at the then()/catch() call site.
        if (! Signed::$signer) {
            throw new SwarmException(
                'Terminal workflow callbacks require APP_KEY: no closure signing key is configured '
                .'in this process. A callback is signed at registration and verified at delivery, '
                .'so one registered without a signing key could never be delivered. Laravel derives '
                .'the signing key from APP_KEY when the application boots, so a key set afterwards '
                .'does not sign. Set APP_KEY in every process that registers or delivers callbacks, '
                .'or listen to the SwarmCompleted / SwarmFailed events instead of then()/catch().'
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
            'claim_token' => null,
            'available_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function settle(string $runId, SwarmTerminalContext $context): void
    {
        if (! $this->isSettlementAvailable()) {
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
            ->where('status', 'registered')
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
        if (! $this->isSettlementAvailable()) {
            return;
        }

        $this->table()->where('run_id', $runId)->delete();
    }

    public function hasFor(string $runId): bool
    {
        if (! $this->isSettlementAvailable()) {
            return false;
        }

        return $this->table()->where('run_id', $runId)->exists();
    }

    public function drain(int $limit = 100): CallbackDrainResult
    {
        // The enabled flag is the operator kill switch: with it off, stop draining and
        // dispatching so already-registered closures cease executing, even mid-incident.
        if ($limit < 1 || ! $this->featureEnabled()) {
            return new CallbackDrainResult(0, 0, 0, 0, 0);
        }

        $this->assertReady();

        $reservationTimeoutSeconds = $this->reservationTimeoutSeconds();
        $maxAttempts = $this->maxAttempts();
        $now = Carbon::now('UTC');
        $staleThreshold = $now->copy()->subSeconds($reservationTimeoutSeconds);

        $result = $this->connection->transaction(function () use ($now, $staleThreshold, $limit, $maxAttempts): array {
            $query = $this->table()
                ->where(function ($query) use ($staleThreshold): void {
                    $query->where(function ($pending) use ($staleThreshold): void {
                        $pending->where('status', 'pending')
                            ->where(function ($lease) use ($staleThreshold): void {
                                $lease->whereNull('reserved_at')
                                    ->orWhere('reserved_at', '<', $staleThreshold);
                            });
                    })->orWhere(function ($delivering) use ($staleThreshold): void {
                        $delivering->where('status', 'delivering')
                            ->whereNotNull('reserved_at')
                            ->where('reserved_at', '<', $staleThreshold);
                    });
                })
                ->where(function ($available) use ($now): void {
                    $available->whereNull('available_at')
                        ->orWhere('available_at', '<=', $now);
                })
                ->orderBy('id')
                ->limit($limit);

            if ($this->connection->getDriverName() !== 'sqlite') {
                $query->lock('for update skip locked');
            }

            $entries = $query->get();

            $claims = [];
            $deadLetters = [];

            foreach ($entries as $entry) {
                $reclaimed = $entry->reserved_at !== null;

                if ((int) $entry->attempts >= $maxAttempts) {
                    $attempts = (int) $entry->attempts;
                    $error = "configured attempt cap of {$maxAttempts} was reached after {$attempts} attempt(s)";
                    $this->table()->where('id', $entry->id)->where('status', $entry->status)->update([
                        'status' => 'dead_letter',
                        'last_error' => $this->cipher->seal($error),
                        'reserved_at' => null,
                        'claim_token' => null,
                        'available_at' => null,
                        'updated_at' => $now,
                    ]);
                    $deadLetters[] = ['row' => $entry, 'error' => $error, 'reclaimed' => $reclaimed];

                    continue;
                }

                $token = bin2hex(random_bytes(32));
                $this->table()->where('id', $entry->id)->update([
                    'status' => 'pending',
                    'claim_token' => $token,
                    'reserved_at' => $now,
                    'available_at' => null,
                    'updated_at' => $now,
                ]);
                $claims[] = ['row' => $entry, 'token' => $token, 'reclaimed' => $reclaimed];
            }

            return ['claims' => $claims, 'dead_letters' => $deadLetters];
        });

        $claims = $result['claims'];
        $deadLetters = $result['dead_letters'];

        foreach ($deadLetters as $deadLetter) {
            $this->logDeadLetter($deadLetter['row'], 'configured attempt cap reached');
        }

        if ($claims === [] && $deadLetters === []) {
            return new CallbackDrainResult(0, 0, 0, 0, 0);
        }

        $claimed = count($claims) + count($deadLetters);
        $reclaimed = count(array_filter($claims, static fn (array $claim): bool => $claim['reclaimed']))
            + count(array_filter($deadLetters, static fn (array $deadLetter): bool => $deadLetter['reclaimed']));

        $dispatched = 0;
        $deadLettered = count($deadLetters);
        $failed = 0;

        foreach ($claims as $claim) {
            $entry = $claim['row'];
            $token = $claim['token'];
            try {
                $job = new DeliverSwarmCallback((int) $entry->id, $token);
                $connection = $this->queueSetting('connection');
                $queue = $this->queueSetting('name');

                if ($connection !== null) {
                    $job->onConnection($connection);
                }

                if ($queue !== null) {
                    $job->onQueue($queue);
                }

                $this->bus->dispatch($job);
                $dispatched++;
            } catch (Throwable $exception) {
                $this->safeReport($exception);
                $this->releaseClaimAfterDispatchFailure((int) $entry->id, $token, mb_substr($exception->getMessage(), 0, 1000));
                $failed++;
            }
        }

        return new CallbackDrainResult($dispatched, $deadLettered, $failed, $claimed, $reclaimed);
    }

    public function deliver(int $id, string $claimToken): void
    {
        // Honor the kill switch here too: an already-dispatched delivery job must not
        // execute a stored closure once the operator has turned the feature off.
        if (! $this->featureEnabled()) {
            return;
        }

        $this->assertReady();

        /** @var object|null $row */
        $row = $this->connection->transaction(function () use ($id, $claimToken): ?object {
            $row = $this->table()
                ->where('id', $id)
                ->where('status', 'pending')
                ->where('claim_token', $claimToken)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }

            $now = Carbon::now('UTC');
            $attempts = (int) $row->attempts + 1;
            $updated = $this->table()
                ->where('id', $id)
                ->where('status', 'pending')
                ->where('claim_token', $claimToken)
                ->update([
                    'status' => 'delivering',
                    'attempts' => $attempts,
                    'last_attempted_at' => $now,
                    'reserved_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return null;
            }

            $row->status = 'delivering';
            $row->attempts = $attempts;
            $row->last_attempted_at = $now;
            $row->reserved_at = $now;

            return $row;
        });

        if ($row === null) {
            return;
        }

        $closure = $this->resolveClosure($row);

        if ($closure === null) {
            // A closure that cannot be unsealed or whose signature does not verify can
            // never be invoked (tamper, or an APP_KEY rotation that invalidated the
            // signature). Dead-letter it permanently rather than reclaiming forever.
            $this->markDeadLetter(
                $id,
                $claimToken,
                (int) $row->attempts,
                $this->unreadableReason($row),
                'callback payload rejected',
            );

            return;
        }

        $context = $this->resolveContext($row);

        try {
            $closure($context);
        } catch (Throwable $exception) {
            // The callback ran in this delivery process, entirely separate from the
            // settled workflow: its failure can neither replay the workflow's model or
            // tool effects nor change the already-recorded terminal result. It only
            // affects this delivery row. The final allowed failure dead-letters; an
            // earlier failure releases the lease after the configured retry backoff.
            $this->safeReport($exception);
            $error = mb_substr($exception->getMessage(), 0, 1000);

            if ((int) $row->attempts >= $this->maxAttempts()) {
                $this->markDeadLetter(
                    $id,
                    $claimToken,
                    (int) $row->attempts,
                    $error,
                    'callback execution failed',
                    $exception::class,
                );
            } else {
                $this->releaseForRetry($id, $claimToken, $error);
            }

            return;
        }

        $deleted = $this->table()
            ->where('id', $id)
            ->where('status', 'delivering')
            ->where('claim_token', $claimToken)
            ->delete();

        if ($deleted === 1) {
            $this->audit->emit('callback.delivered', [
                'delivery_id' => $id,
                'run_id' => (string) $row->run_id,
                'slot' => (string) $row->slot,
                'attempts' => (int) $row->attempts,
            ]);
        }
    }

    protected function resolveClosure(object $row): ?Closure
    {
        try {
            $raw = is_string($row->callback) ? $this->cipher->openStrict($row->callback) : null;

            if (! is_string($raw)) {
                return null;
            }

            // With no signing key in this process nothing can be verified, so refuse
            // before deserializing rather than rely on the library to reject it.
            if (! Signed::$signer) {
                return null;
            }

            $restored = unserialize($raw, ['allowed_classes' => self::CLOSURE_CLASSES]);

            if (! $restored instanceof SerializableClosure) {
                return null;
            }

            return $restored->getClosure();
        } catch (Throwable $exception) {
            // Includes InvalidSignatureException (tampered payload or an APP_KEY
            // rotation) and decrypt failures — all permanent, all routed to
            // dead-letter by the caller. unreadableReason() categorizes them.
            $this->safeReport($exception);

            return null;
        }
    }

    /**
     * Categorize why a stored closure could not be resolved, so the dead-letter
     * record distinguishes a routine APP_KEY rotation from an actual tampering
     * attempt (both fail closed, but an operator needs to tell them apart).
     */
    protected function unreadableReason(object $row): string
    {
        if (! is_string($row->callback)) {
            return 'callback payload missing';
        }

        try {
            $raw = $this->cipher->openStrict($row->callback);
        } catch (Throwable) {
            return 'callback payload could not be decrypted (possible APP_KEY rotation)';
        }

        if (! is_string($raw)) {
            return 'callback payload could not be decrypted (possible APP_KEY rotation)';
        }

        // resolveClosure() refuses to deserialize without a signing key; name that
        // cause, and do not deserialize here either.
        if (! Signed::$signer) {
            return self::NO_SIGNING_KEY_REASON;
        }

        try {
            $restored = unserialize($raw, ['allowed_classes' => self::CLOSURE_CLASSES]);
        } catch (Throwable $exception) {
            // SerializableClosure verifies its HMAC on unserialize and throws
            // InvalidSignatureException on a bad signature (tamper or an APP_KEY rotation).
            return $exception instanceof InvalidSignatureException
                ? 'callback signature verification failed (payload tampering or APP_KEY rotation)'
                : 'callback payload is not a readable serialized closure';
        }

        return $restored instanceof SerializableClosure
            ? 'callback resolved but could not be invoked'
            : 'callback payload is not a serialized closure';
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
            exceptionClass: is_string($decoded['exception_class'] ?? null) ? $decoded['exception_class'] : null,
            exceptionMessage: is_string($decoded['exception_message'] ?? null) ? $decoded['exception_message'] : null,
        );
    }

    protected function encodeContext(SwarmTerminalContext $context): string
    {
        return json_encode($context->toArray(), JSON_THROW_ON_ERROR);
    }

    protected function featureEnabled(): bool
    {
        return (bool) $this->config->get('swarm.callbacks.enabled', false);
    }

    /**
     * Reservation timeout for the callback lane. Prefers a callback-specific value so
     * operators can size it for slow callbacks, falling back to the shared durable
     * relay timeout for backward compatibility.
     */
    protected function reservationTimeoutSeconds(): int
    {
        $callback = $this->config->get('swarm.callbacks.reservation_timeout_seconds');

        if (is_int($callback) && $callback > 0) {
            return min(86400, $callback);
        }

        return max(1, min(86400, (int) $this->config->get('swarm.durable.relay.reservation_timeout_seconds', 60)));
    }

    protected function releaseForRetry(int $id, string $claimToken, string $error): void
    {
        $now = Carbon::now('UTC');

        $this->table()
            ->where('id', $id)
            ->where('status', 'delivering')
            ->where('claim_token', $claimToken)
            ->update([
                'status' => 'pending',
                'last_error' => $this->cipher->seal($error),
                'reserved_at' => null,
                'claim_token' => null,
                'available_at' => $now->copy()->addSeconds($this->retryBackoffSeconds()),
                'updated_at' => $now,
            ]);
    }

    protected function releaseClaimAfterDispatchFailure(int $id, string $claimToken, string $error): void
    {
        $now = Carbon::now('UTC');

        $this->table()
            ->where('id', $id)
            ->where('status', 'pending')
            ->where('claim_token', $claimToken)
            ->update([
                'last_error' => $this->cipher->seal($error),
                'reserved_at' => null,
                'claim_token' => null,
                'available_at' => $now->copy()->addSeconds($this->retryBackoffSeconds()),
                'updated_at' => $now,
            ]);
    }

    protected function markDeadLetter(
        int $id,
        string $claimToken,
        int $attempts,
        string $error,
        string $reason,
        ?string $exceptionClass = null,
    ): void {
        $now = Carbon::now('UTC');
        $row = $this->table()->where('id', $id)->first(['run_id', 'slot']);
        $updated = $this->table()
            ->where('id', $id)
            ->where('status', 'delivering')
            ->where('claim_token', $claimToken)
            ->update([
                'status' => 'dead_letter',
                'last_error' => $this->cipher->seal($error),
                'last_attempted_at' => $now,
                'reserved_at' => null,
                'claim_token' => null,
                'available_at' => null,
                'updated_at' => $now,
            ]);

        if ($updated !== 1 || $row === null) {
            return;
        }

        $row->id = $id;
        $row->attempts = $attempts;
        $this->logDeadLetter($row, $reason, $exceptionClass);
    }

    protected function logDeadLetter(object $row, string $reason, ?string $exceptionClass = null): void
    {
        $this->safeLog($this->logger, 'error', 'Swarm terminal callback reached dead_letter status.', [
            'id' => (int) $row->id,
            'run_id' => (string) $row->run_id,
            'slot' => (string) $row->slot,
            'attempts' => (int) $row->attempts,
            'reason' => mb_substr($reason, 0, 100),
            'exception_class' => $exceptionClass,
        ]);
    }

    protected function maxAttempts(): int
    {
        return max(1, min(1000, (int) $this->config->get('swarm.callbacks.max_attempts', 5)));
    }

    protected function retryBackoffSeconds(): int
    {
        return max(0, min(86400, (int) $this->config->get('swarm.callbacks.retry_backoff_seconds', 60)));
    }

    protected function queueSetting(string $key): ?string
    {
        $value = $this->config->get('swarm.callbacks.queue.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function staleWarningThresholdSeconds(): int
    {
        $configured = (int) $this->config->get('swarm.callbacks.stale_warning_threshold_seconds', 0);

        return $configured > 0
            ? min(604800, $configured)
            : min(604800, $this->reservationTimeoutSeconds() * 2);
    }

    /**
     * Terminal settlement uses only columns from the original callback table.
     * Cache only the table-existence probe so the common callback-free terminal
     * write pays one cold schema query and one indexed run_id existence query.
     */
    protected function isSettlementAvailable(): bool
    {
        if ($this->settlementAvailability !== null) {
            return $this->settlementAvailability;
        }

        if ($this->config->get('swarm.persistence.driver') !== 'database') {
            return $this->settlementAvailability = false;
        }

        return $this->settlementAvailability = $this->connection
            ->getSchemaBuilder()
            ->hasTable($this->tableName());
    }

    public function isAvailable(): bool
    {
        if ($this->readiness !== null) {
            return $this->readiness;
        }

        $table = $this->tableName();

        if ($this->config->get('swarm.persistence.driver') !== 'database') {
            $this->readinessFailure = 'Terminal workflow callbacks require database-backed persistence. Set '
                .'swarm.persistence.driver to "database" and run the package migrations, or listen '
                .'to the SwarmCompleted / SwarmFailed events instead of then()/catch().';

            return $this->readiness = false;
        }

        try {
            $schema = $this->connection->getSchemaBuilder();

            if (! $schema->hasTable($table)) {
                $this->readinessFailure = "Callback delivery outbox requires the [{$table}] table. Run the package migrations and restart callback workers.";

                return $this->readiness = false;
            }

            if (! $schema->hasColumns($table, ['claim_token', 'available_at'])) {
                $this->readinessFailure = "Callback delivery outbox requires [{$table}.claim_token] and [{$table}.available_at]. Bring the unreleased callback migration schema up to date and restart callback workers.";

                return $this->readiness = false;
            }

            return $this->readiness = true;
        } catch (Throwable $exception) {
            $this->readinessFailure = "Callback delivery outbox readiness failed for [{$table}]: {$exception->getMessage()}";

            return $this->readiness = false;
        }
    }

    public function assertReady(): void
    {
        if (! $this->isAvailable()) {
            throw new SwarmException($this->readinessFailure ?? 'Callback delivery outbox is not available.');
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
            if ($this->readinessFailure !== null) {
                throw new SwarmException($this->readinessFailure);
            }

            return [
                'available' => false,
                'registered' => 0,
                'pending' => 0,
                'delivering' => 0,
                'dead_letter' => 0,
                'fresh_reservations' => 0,
                'stale_pending_reservations' => 0,
                'stale_deliveries' => 0,
                'aged_eligible' => 0,
                'oldest_eligible_at' => null,
            ];
        }

        $now = Carbon::now('UTC');
        $freshThreshold = $now->copy()->subSeconds($this->reservationTimeoutSeconds());
        $agedThreshold = $now->copy()->subSeconds($this->staleWarningThresholdSeconds());

        $summary = $this->table()->selectRaw(<<<'SQL'
            COALESCE(SUM(CASE WHEN status = 'registered' THEN 1 ELSE 0 END), 0) AS registered,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status = 'delivering' THEN 1 ELSE 0 END), 0) AS delivering,
            COALESCE(SUM(CASE WHEN status = 'dead_letter' THEN 1 ELSE 0 END), 0) AS dead_letter,
            COALESCE(SUM(CASE WHEN status IN ('pending', 'delivering') AND reserved_at IS NOT NULL AND reserved_at >= ? THEN 1 ELSE 0 END), 0) AS fresh_reservations,
            COALESCE(SUM(CASE WHEN status = 'pending' AND reserved_at IS NOT NULL AND reserved_at < ? THEN 1 ELSE 0 END), 0) AS stale_pending_reservations,
            COALESCE(SUM(CASE WHEN status = 'delivering' AND reserved_at IS NOT NULL AND reserved_at < ? THEN 1 ELSE 0 END), 0) AS stale_deliveries,
            COALESCE(SUM(CASE WHEN status = 'pending' AND reserved_at IS NULL AND (available_at IS NULL OR available_at <= ?) AND ((available_at IS NOT NULL AND available_at <= ?) OR (available_at IS NULL AND created_at <= ?)) THEN 1 ELSE 0 END), 0) AS aged_eligible,
            MIN(CASE WHEN status = 'pending' AND reserved_at IS NULL AND (available_at IS NULL OR available_at <= ?) THEN COALESCE(available_at, created_at) ELSE NULL END) AS oldest_eligible_at
            SQL, [
            $freshThreshold,
            $freshThreshold,
            $freshThreshold,
            $now,
            $agedThreshold,
            $agedThreshold,
            $now,
        ])->first();

        $oldestEligibleAt = $summary->oldest_eligible_at;

        return [
            'available' => true,
            'registered' => (int) ($summary->registered ?? 0),
            'pending' => (int) ($summary->pending ?? 0),
            'delivering' => (int) ($summary->delivering ?? 0),
            'dead_letter' => (int) ($summary->dead_letter ?? 0),
            'fresh_reservations' => (int) ($summary->fresh_reservations ?? 0),
            'stale_pending_reservations' => (int) ($summary->stale_pending_reservations ?? 0),
            'stale_deliveries' => (int) ($summary->stale_deliveries ?? 0),
            'aged_eligible' => (int) ($summary->aged_eligible ?? 0),
            'oldest_eligible_at' => is_string($oldestEligibleAt) ? $oldestEligibleAt : null,
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
