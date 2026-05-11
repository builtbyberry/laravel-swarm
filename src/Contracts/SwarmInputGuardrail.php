<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

/** Validates swarm task input before any agent runs. */
interface SwarmInputGuardrail
{
    public function validate(RunContext $context): void;
}
