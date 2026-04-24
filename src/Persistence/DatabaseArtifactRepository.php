<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Support\DatabaseTtl;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;

class DatabaseArtifactRepository implements ArtifactRepository
{
    use InteractsWithJsonColumns;

    public function __construct(
        protected ConnectionInterface $connection,
        protected ConfigRepository $config,
    ) {}

    public function storeMany(string $runId, array $artifacts, int $ttlSeconds): void
    {
        $timestamp = now();

        foreach ($artifacts as $artifact) {
            $payload = $artifact instanceof SwarmArtifact ? $artifact->toArray() : (array) $artifact;

            $this->table()->insert([
                'run_id' => $runId,
                'name' => (string) ($payload['name'] ?? ''),
                'content' => $this->encodeJson($payload['content'] ?? null),
                'metadata' => $this->encodeJson($payload['metadata'] ?? []),
                'step_agent_class' => $payload['step_agent_class'] ?? null,
                'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    public function all(string $runId): array
    {
        return $this->table()
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $record): array => [
                'name' => $record->name,
                'content' => $this->decodeJson($record->content, null),
                'metadata' => $this->decodeJson($record->metadata, []),
                'step_agent_class' => $record->step_agent_class,
            ])
            ->all();
    }

    public function assertReady(): void
    {
        $table = (string) $this->config->get('swarm.tables.artifacts', 'swarm_artifacts');
        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            throw new SwarmException("Database-backed durable swarms require the [{$table}] table.");
        }

        if (! $schema->hasColumns($table, ['id', 'run_id', 'name', 'content', 'metadata', 'step_agent_class', 'created_at', 'updated_at', 'expires_at'])) {
            throw new SwarmException("Database-backed durable swarms require runtime columns on [{$table}] for artifact persistence.");
        }
    }

    protected function table()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.artifacts', 'swarm_artifacts'));
    }
}
