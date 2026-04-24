<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Support\DatabaseTtl;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;

class DatabaseContextStore implements ContextStore
{
    use InteractsWithJsonColumns;

    public function __construct(
        protected ConnectionInterface $connection,
        protected ConfigRepository $config,
    ) {}

    public function put(RunContext $context, int $ttlSeconds): void
    {
        $payload = [
            'run_id' => $context->runId,
            'input' => $context->input,
            'data' => $this->encodeJson($context->data),
            'metadata' => $this->encodeJson($context->metadata),
            'artifacts' => $this->encodeJson(array_map(
                static fn ($artifact): array => $artifact->toArray(),
                $context->artifacts,
            )),
            'updated_at' => now(),
            'expires_at' => DatabaseTtl::expiresAt($ttlSeconds),
        ];

        $exists = $this->table()->where('run_id', $context->runId)->exists();

        if ($exists) {
            $this->table()->where('run_id', $context->runId)->update($payload);

            return;
        }

        $payload['created_at'] = $payload['updated_at'];

        $this->table()->insert($payload);
    }

    public function find(string $runId): ?array
    {
        /** @var object|null $record */
        $record = $this->table()->where('run_id', $runId)->first();

        if ($record === null) {
            return null;
        }

        return [
            'run_id' => $record->run_id,
            'input' => $record->input,
            'data' => $this->decodeJson($record->data, []),
            'metadata' => $this->decodeJson($record->metadata, []),
            'artifacts' => $this->decodeJson($record->artifacts, []),
        ];
    }

    protected function table()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.contexts', 'swarm_contexts'));
    }
}
