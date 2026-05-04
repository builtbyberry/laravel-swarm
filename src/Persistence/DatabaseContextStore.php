<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Support\DatabaseTtl;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

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
            'input' => $this->cipher->open((string) $record->input),
            'data' => $this->decodeJson($record->data, []),
            'metadata' => $this->decodeJson($record->metadata, []),
            'artifacts' => $this->decodeJson($record->artifacts, []),
        ];
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
