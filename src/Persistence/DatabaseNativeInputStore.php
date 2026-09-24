<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManifest;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use JsonException;

final class DatabaseNativeInputStore implements NativeInputStore
{
    public function __construct(
        protected Connection $connection,
        protected SwarmPersistenceCipher $cipher,
        protected ConfigRepository $config,
    ) {}

    public function put(string $id, string $runId, array $payload, string $hash, int $expiresAt): void
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SwarmException('The native input operational envelope could not be encoded.', previous: $exception);
        }

        $sealed = $this->cipher->seal($encoded);
        if (! is_string($sealed) || ! str_starts_with($sealed, SwarmPersistenceCipher::PREFIX)) {
            throw new SwarmException('Native input operational envelopes must be sealed before persistence.');
        }

        $now = now();
        $this->connection->table($this->table())->insert([
            'id' => $id,
            'run_id' => $runId,
            'format_version' => NativeInputManifest::VERSION,
            'state' => 'staged',
            'payload' => $sealed,
            'payload_hash' => hash('sha256', $sealed),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function find(string $id): ?array
    {
        $row = $this->connection->table($this->table())->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        $sealed = (string) $row->payload;
        if (! str_starts_with($sealed, SwarmPersistenceCipher::PREFIX)
            || ! hash_equals((string) $row->payload_hash, hash('sha256', $sealed))) {
            throw new SwarmException("Native input envelope [{$id}] failed its sealed content identity check.");
        }

        $decoded = json_decode((string) $this->cipher->openStrict($sealed), true, 512, JSON_THROW_ON_ERROR);

        return [
            'run_id' => (string) $row->run_id,
            'payload' => is_array($decoded) ? $decoded : [],
            'hash' => hash('sha256', json_encode($decoded, JSON_THROW_ON_ERROR)),
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
        $updated = $this->connection->table($this->table())
            ->where('id', $id)->where('run_id', $runId)
            ->update(['state' => $state, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new SwarmException("Native input envelope [{$id}] is unavailable for run [{$runId}].");
        }
    }

    protected function table(): string
    {
        return (string) $this->config->get('swarm.tables.native_inputs', 'swarm_native_inputs');
    }
}
