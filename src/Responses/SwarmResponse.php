<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

class SwarmResponse
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
}
