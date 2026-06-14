<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Support\DatabaseTtl;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * @internal
 */
class DatabaseContextStore implements ContextStore
{
    use InteractsWithJsonColumns;

    public function __construct(
        protected Connection $connection,
        protected ConfigRepository $config,
        protected SwarmPersistenceCipher $cipher,
    ) {}

    public function put(RunContext $context, int $ttlSeconds): void
    {
        // The active-context store is operational runtime state: it is the only
        // persisted source of the top-level input for durable resume, so it
        // always retains the true (sealed) input. CaptureDecision::Skip is
        // honored on the *evidence* surfaces (run history, events, audit), not
        // here. RunContext::input is a non-null string.
        $contextPayload = $context->toArray();
        $payload = [
            'run_id' => $contextPayload['run_id'],
            'input' => $this->cipher->seal($contextPayload['input']),
            'data' => $this->encodeJson($contextPayload['data']),
            'metadata' => $this->encodeJson($contextPayload['metadata']),
            'artifacts' => $this->encodeJson($contextPayload['artifacts']),
            'updated_at' => now(),
            'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
        ];

        $this->table()->upsert(
            [array_merge($payload, ['created_at' => $payload['updated_at']])],
            ['run_id'],
            ['input', 'data', 'metadata', 'artifacts', 'updated_at', 'expires_at'],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array
    {
        /** @var object|null $record */
        $record = $this->table()->where('run_id', $runId)->first();

        if ($record === null) {
            return null;
        }

        return [
            'run_id' => $record->run_id,
            // swarm_contexts.input is the run's top-level resume prompt — operational
            // resume state, not an evidence surface (find() feeds RunContext::fromPayload
            // on every durable/queued resume; there is no display reader). So it decrypts
            // STRICTLY (#212): a wrong/rotated APP_KEY fails the resume loud with a
            // SwarmException rather than silently feeding a null/ciphertext prompt into the
            // resumed step under the display decrypt_failure_policy. Defensive null-tolerance
            // is kept for a stale row from an interim nullable schema.
            'input' => $record->input !== null ? $this->openInputStrict((string) $record->input, $runId) : null,
            'data' => $this->decodeJson($record->data, []),
            'metadata' => $this->decodeJson($record->metadata, []),
            'artifacts' => $this->decodeJson($record->artifacts, []),
        ];
    }

    /**
     * Strictly decrypt the operational resume input, failing loud on a wrong/rotated
     * APP_KEY (#212). Mirrors the durable runtime's operational reads: an undecryptable
     * resume prompt throws a clear SwarmException so the run fails visibly and is
     * re-dispatchable, rather than silently resuming from null/ciphertext.
     */
    private function openInputStrict(string $value, string $runId): ?string
    {
        try {
            return $this->cipher->openStrict($value);
        } catch (DecryptException $e) {
            throw new SwarmException(
                "Cannot decrypt durable resume input for run [{$runId}]; verify APP_KEY matches the key used to encrypt stored rows.",
                previous: $e,
            );
        }
    }

    public function assertReady(): void
    {
        $table = (string) $this->config->get('swarm.tables.contexts', 'swarm_contexts');
        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            throw new SwarmException("Database-backed durable swarms require the [{$table}] table.");
        }

        if (! $schema->hasColumns($table, ['run_id', 'input', 'data', 'metadata', 'artifacts', 'created_at', 'updated_at', 'expires_at'])) {
            throw new SwarmException("Database-backed durable swarms require runtime columns on [{$table}] for persisted context state.");
        }
    }

    protected function table(): Builder
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.contexts', 'swarm_contexts'));
    }
}
