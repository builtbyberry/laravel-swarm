<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

class SwarmResponse
{
    /**
     * @param  array<int, SwarmStep>  $steps
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public readonly string $output,
        public readonly array $steps = [],
        public readonly array $usage = [],
    ) {}

    /**
     * Cast the response to a string.
     */
    public function __toString(): string
    {
        return $this->output;
    }
}
