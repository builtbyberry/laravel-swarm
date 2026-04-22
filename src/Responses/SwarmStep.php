<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

final class SwarmStep
{
    /**
     * @param  class-string  $agentClass
     */
    public function __construct(
        public readonly string $agentClass,
        public readonly string $input,
        public readonly string $output,
    ) {}
}
