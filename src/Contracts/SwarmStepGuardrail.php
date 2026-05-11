<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;

/** Validates each agent step after output is available and before step completion is recorded. */
interface SwarmStepGuardrail
{
    public function validate(GuardrailStepContext $context): void;
}
