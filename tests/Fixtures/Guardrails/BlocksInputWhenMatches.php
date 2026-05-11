<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmInputGuardrail;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

final class BlocksInputWhenMatches implements SwarmInputGuardrail
{
    public function __construct(private string $blockedToken = '__guardrail_never_matches__') {}

    public function validate(RunContext $context): void
    {
        if ($context->input === $this->blockedToken) {
            throw GuardrailViolation::block('blocked_input', 'input blocked', [], 'input');
        }
    }
}
