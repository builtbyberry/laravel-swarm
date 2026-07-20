<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
class SwarmArtifact implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly mixed $content,
        public readonly array $metadata = [],
        public readonly ?string $stepAgentClass = null,
    ) {}

    /**
     * @return array{name: string, content: mixed, metadata: array<string, mixed>, step_agent_class: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'step_agent_class' => $this->stepAgentClass,
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
