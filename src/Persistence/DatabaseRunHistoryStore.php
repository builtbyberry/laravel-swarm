<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Throwable;

class DatabaseRunHistoryStore implements RunHistoryStore
{
    use InteractsWithJsonColumns;

    public function __construct(
        protected ConnectionInterface $connection,
        protected ConfigRepository $config,
    ) {}

    public function start(string $runId, string $swarmClass, string $topology, RunContext $context, array $metadata, int $ttlSeconds): void
    {
        $timestamp = now();

        $this->table()->updateOrInsert(
            ['run_id' => $runId],
            [
                'swarm_class' => $swarmClass,
                'topology' => $topology,
                'status' => 'running',
                'context' => $this->encodeJson($context->toArray()),
                'metadata' => $this->encodeJson($metadata),
                'steps' => $this->encodeJson([]),
                'output' => null,
                'usage' => $this->encodeJson([]),
                'error' => null,
                'artifacts' => $this->encodeJson([]),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        );
    }

    public function recordStep(string $runId, SwarmStep $step, int $ttlSeconds): void
    {
        $history = $this->find($runId) ?? [];
        $history['steps'] ??= [];
        $history['steps'][] = $step->toArray();

        $this->update($runId, [
            'steps' => $this->encodeJson($history['steps']),
        ]);
    }

    public function complete(string $runId, SwarmResponse $response, int $ttlSeconds): void
    {
        $this->update($runId, [
            'status' => 'completed',
            'output' => $response->output,
            'usage' => $this->encodeJson($response->usage),
            'context' => $this->encodeJson($response->context?->toArray()),
            'artifacts' => $this->encodeJson(array_map(static fn ($artifact): array => $artifact->toArray(), $response->artifacts)),
            'metadata' => $this->encodeJson($response->metadata),
        ]);
    }

    public function fail(string $runId, Throwable $exception, int $ttlSeconds): void
    {
        $this->update($runId, [
            'status' => 'failed',
            'error' => $this->encodeJson([
                'message' => $exception->getMessage(),
                'class' => $exception::class,
            ]),
        ]);
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
            'swarm_class' => $record->swarm_class,
            'topology' => $record->topology,
            'status' => $record->status,
            'context' => $this->decodeJson($record->context, []),
            'metadata' => $this->decodeJson($record->metadata, []),
            'steps' => $this->decodeJson($record->steps, []),
            'output' => $record->output,
            'usage' => $this->decodeJson($record->usage, []),
            'error' => $this->decodeJson($record->error, null),
            'artifacts' => $this->decodeJson($record->artifacts, []),
        ];
    }

    protected function update(string $runId, array $values): void
    {
        $values['updated_at'] = now();

        $this->table()->where('run_id', $runId)->update($values);
    }

    protected function table()
    {
        return $this->connection->table((string) $this->config->get('swarm.tables.history', 'swarm_run_histories'));
    }
}

