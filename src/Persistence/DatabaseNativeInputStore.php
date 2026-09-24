<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManifest;
use Illuminate\Database\Connection;
use JsonException;

final class DatabaseNativeInputStore implements NativeInputStore
{
    public function __construct(
        protected Connection $connection,
        protected SwarmPersistenceCipher $cipher,
    ) {}

    public function put(string $id, string $runId, array $payload, string $hash, int $expiresAt): void
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SwarmException('The native input operational envelope could not be encoded.', previous: $exception);
        }

        $now = now();
        $this->connection->table(config('swarm.tables.native_inputs', 'swarm_native_inputs'))->insert([
            'id' => $id,
            'run_id' => $runId,
            'format_version' => NativeInputManifest::VERSION,
            'state' => 'staged',
            'payload' => $this->cipher->seal($encoded),
            'payload_hash' => $hash,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function find(string $id): ?array
    {
        $row = $this->connection->table(config('swarm.tables.native_inputs', 'swarm_native_inputs'))->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $this->cipher->openStrict((string) $row->payload), true, 512, JSON_THROW_ON_ERROR);

        return [
            'run_id' => (string) $row->run_id,
            'payload' => is_array($decoded) ? $decoded : [],
            'hash' => (string) $row->payload_hash,
            'state' => (string) $row->state,
            'expires_at' => strtotime((string) $row->expires_at) ?: 0,
        ];
    }

    public function activate(string $id, string $runId): void
    {
        $this->transition($id, $runId, 'active');
    }

    public function revoke(string $id, string $runId): void
    {
        $this->transition($id, $runId, 'revoked');
    }

    protected function transition(string $id, string $runId, string $state): void
    {
        $updated = $this->connection->table(config('swarm.tables.native_inputs', 'swarm_native_inputs'))
            ->where('id', $id)->where('run_id', $runId)
            ->update(['state' => $state, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new SwarmException("Native input envelope [{$id}] is unavailable for run [{$runId}].");
        }
    }
}
