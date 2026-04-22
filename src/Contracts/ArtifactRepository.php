<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;

interface ArtifactRepository
{
    /**
     * @param  array<int, SwarmArtifact>  $artifacts
     */
    public function storeMany(string $runId, array $artifacts, int $ttlSeconds): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(string $runId): array;
}
