<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmStepGuardrail;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;

class RejectSuccessGuardrail implements SwarmStepGuardrail
{
    public function validate(GuardrailStepContext $context): void
    {
        if ($context->agentClass === PendingAgent::class) {
            throw new \RuntimeException('Pending outcome reached success guardrails.');
        }
    }
}
