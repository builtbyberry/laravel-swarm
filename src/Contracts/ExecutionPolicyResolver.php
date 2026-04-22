<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;

interface ExecutionPolicyResolver
{
    public function resolve(Swarm $swarm): ExecutionMode;
}
