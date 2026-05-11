<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

/** Validates final swarm output before successful completion is persisted. */
interface SwarmOutputGuardrail
{
    public function validate(RunContext $context, string $output): void;
}
