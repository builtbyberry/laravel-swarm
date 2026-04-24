<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class UnresolvableParallelAgent implements Agent
{
    use Promptable;

    public function __construct(
        protected string $runtimeValue,
    ) {}

    public function instructions(): string
    {
        return 'You are intentionally not container resolvable.';
    }
}
