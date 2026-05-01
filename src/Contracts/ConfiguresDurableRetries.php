<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Responses\DurableRetryPolicy;

interface ConfiguresDurableRetries
{
    public function durableRetryPolicy(): DurableRetryPolicy;

    public function durableAgentRetryPolicy(string $agentClass): ?DurableRetryPolicy;
}
