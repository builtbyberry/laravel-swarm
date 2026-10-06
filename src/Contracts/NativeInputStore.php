<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

interface NativeInputStore
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(string $id, string $runId, array $payload, string $hash, int $expiresAt): void;

    /**
     * @return array{run_id: string, format_version?: int, payload: array<string, mixed>, hash: string, state: string, expires_at: int}|null
     */
    public function find(string $id): ?array;

    /** Mark the matching envelope active without changing its payload or expiry. */
    public function activate(string $id, string $runId): void;

    /** Mark the matching envelope revoked and immediately expired. */
    public function revoke(string $id, string $runId): void;
}
