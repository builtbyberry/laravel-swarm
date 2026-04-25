<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;

class ArtifactPayload
{
    /**
     * @return array{name: string, content: mixed, metadata: array<string, mixed>, step_agent_class: string|null}
     */
    public static function normalize(mixed $artifact, string $path = 'artifact'): array
    {
        if ($artifact instanceof SwarmArtifact) {
            $payload = $artifact->toArray();
        } elseif (is_array($artifact)) {
            $payload = $artifact;
        } else {
            throw new SwarmException("Swarm artifact payload [{$path}] must be a SwarmArtifact or array.");
        }

        if (! array_key_exists('name', $payload) || ! is_string($payload['name'])) {
            throw new SwarmException("Swarm artifact payload [{$path}.name] must be a string.");
        }

        if (array_key_exists('metadata', $payload) && ! is_array($payload['metadata'])) {
            throw new SwarmException("Swarm artifact metadata [{$path}.metadata] must be an array.");
        }

        if (array_key_exists('step_agent_class', $payload) && $payload['step_agent_class'] !== null && ! is_string($payload['step_agent_class'])) {
            throw new SwarmException("Swarm artifact payload [{$path}.step_agent_class] must be a string or null.");
        }

        return [
            'name' => $payload['name'],
            'content' => PlainData::value($payload['content'] ?? null, "{$path}.content"),
            'metadata' => PlainData::array($payload['metadata'] ?? [], "{$path}.metadata"),
            'step_agent_class' => $payload['step_agent_class'] ?? null,
        ];
    }
}
