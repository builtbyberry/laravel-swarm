<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ConsumesNativeInputMessages;
use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use JsonException;

final class DatabaseNativeInputStore implements ConsumesNativeInputMessages, NativeInputStore
{
    public function __construct(
        protected Connection $connection,
        protected SwarmPersistenceCipher $cipher,
        protected ConfigRepository $config,
    ) {}

    public function put(string $id, string $runId, array $payload, string $hash, int $expiresAt): void
    {
        if ($this->connection->transactionLevel() > 0) {
            throw new SwarmException('Recoverable native input must be dispatched outside an open database transaction so its cleanup locator cannot be rolled back after file promotion.');
        }

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
            'format_version' => (int) ($payload['version'] ?? 0),
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
            'format_version' => (int) $row->format_version,
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
        $updated = $this->connection->table($this->table())
            ->where('id', $id)->where('run_id', $runId)
            ->update(['state' => 'revoked', 'expires_at' => now(), 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new SwarmException("Native input envelope [{$id}] is unavailable for run [{$runId}].");
        }
    }

    public function consumeMessages(string $id, string $runId, array $configurationIds): void
    {
        if ($configurationIds === []) {
            return;
        }

        $row = $this->connection->table($this->table())
            ->where('id', $id)->where('run_id', $runId)->lockForUpdate()->first();
        if ($row === null || (string) $row->state !== 'active') {
            throw new SwarmException("Native input envelope [{$id}] is unavailable for run [{$runId}].");
        }

        $sealed = (string) $row->payload;
        if (! str_starts_with($sealed, SwarmPersistenceCipher::PREFIX)
            || ! hash_equals((string) $row->payload_hash, hash('sha256', $sealed))) {
            throw new SwarmException("Native input envelope [{$id}] failed its sealed content identity check.");
        }

        $payload = json_decode((string) $this->cipher->openStrict($sealed), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new SwarmException("Native input envelope [{$id}] contains an invalid payload.");
        }

        $known = [];
        foreach ($payload['recipients'] ?? [] as $recipient) {
            if (is_array($recipient) && is_string($recipient['configuration_id'] ?? null)) {
                $known[] = $recipient['configuration_id'];
            }
        }
        foreach ($configurationIds as $configurationId) {
            if (! is_string($configurationId) || ! in_array($configurationId, $known, true)) {
                $label = is_scalar($configurationId) ? (string) $configurationId : get_debug_type($configurationId);
                throw new SwarmException("Native input envelope [{$id}] cannot consume unknown configuration ID [{$label}].");
            }
        }

        $existing = is_array($payload['consumed_message_configuration_ids'] ?? null)
            ? $payload['consumed_message_configuration_ids'] : [];
        $payload['consumed_message_configuration_ids'] = array_values(array_unique(array_merge($existing, $configurationIds)));

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $updatedSealed = $this->cipher->seal($encoded);
        if (! is_string($updatedSealed) || ! str_starts_with($updatedSealed, SwarmPersistenceCipher::PREFIX)) {
            throw new SwarmException('Native input operational envelopes must remain sealed during one-shot message consumption.');
        }

        $updated = $this->connection->table($this->table())
            ->where('id', $id)->where('run_id', $runId)
            ->where('payload_hash', (string) $row->payload_hash)
            ->update([
                'payload' => $updatedSealed,
                'payload_hash' => hash('sha256', $updatedSealed),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new SwarmException("Native input envelope [{$id}] changed while consuming one-shot message configuration.");
        }
    }

    /**
     * @template TCallbackReturnType
     *
     * @param  Closure(): TCallbackReturnType  $callback
     * @return TCallbackReturnType
     */
    public function transaction(Closure $callback): mixed
    {
        return $this->connection->transaction($callback);
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
