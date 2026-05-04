<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class SwarmStep implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, SwarmArtifact>  $artifacts
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $agentClass,
        public readonly string $input,
        public readonly string $output,
        public readonly array $artifacts = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array{agent_class: string, input: string, output: string, artifacts: array<int, array{name: string, content: mixed, metadata: array<string, mixed>, step_agent_class: string|null}>, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'agent_class' => $this->agentClass,
            'input' => $this->input,
            'output' => $this->output,
            'artifacts' => array_map(
                static fn (SwarmArtifact $artifact): array => $artifact->toArray(),
                $this->artifacts,
            ),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
