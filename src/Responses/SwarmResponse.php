<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
class SwarmResponse implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, SwarmStep>  $steps
     * @param  array<string, mixed>  $usage
     * @param  array<int, SwarmArtifact>  $artifacts
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $output,
        public readonly array $steps = [],
        public readonly array $usage = [],
        public readonly ?RunContext $context = null,
        public readonly array $artifacts = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * Cast the response to a string.
     */
    public function __toString(): string
    {
        return $this->output;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'steps' => array_map(fn (SwarmStep $s): array => $s->toArray(), $this->steps),
            'usage' => $this->usage,
            'artifacts' => array_map(fn (SwarmArtifact $a): array => $a->toArray(), $this->artifacts),
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
