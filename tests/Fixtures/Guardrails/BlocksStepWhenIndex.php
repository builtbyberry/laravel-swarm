<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmStepGuardrail;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;

final class BlocksStepWhenIndex implements SwarmStepGuardrail
{
    public function __construct(private int $index = -1) {}

    public function validate(GuardrailStepContext $step): void
    {
        if ($step->stepIndex === $this->index) {
            throw GuardrailViolation::block('blocked_step', 'step blocked', [], 'step');
        }
    }
}
