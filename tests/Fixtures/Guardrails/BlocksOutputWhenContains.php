<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmOutputGuardrail;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

final class BlocksOutputWhenContains implements SwarmOutputGuardrail
{
    public function __construct(private string $needle = '__guardrail_never_matches__') {}

    public function validate(RunContext $context, string $output): void
    {
        if (str_contains($output, $this->needle)) {
            throw GuardrailViolation::block('blocked_output', 'output blocked', [], 'output');
        }
    }
}
