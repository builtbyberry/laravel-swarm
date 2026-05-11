<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails;

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmStepGuardrail;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;

/**
 * Asserts whether step rows exist before step-index-one validation runs (parallel sync policies).
 */
final class ParallelPolicyProbeStepGuardrail implements SwarmStepGuardrail
{
    public function __construct(
        private RunHistoryStore $history,
        private bool $expectAtLeastOneRecordedStepBeforeValidatingIndexOne,
    ) {}

    public function validate(GuardrailStepContext $step): void
    {
        if ($step->stepIndex !== 1) {
            return;
        }

        $row = $this->history->find($step->runId);
        $steps = $row['steps'] ?? [];
        $count = is_array($steps) ? count($steps) : 0;

        if ($this->expectAtLeastOneRecordedStepBeforeValidatingIndexOne && $count < 1) {
            throw GuardrailViolation::block('parallel_probe', 'expected first parallel step recorded before validating index 1');
        }

        if (! $this->expectAtLeastOneRecordedStepBeforeValidatingIndexOne && $count > 0) {
            throw GuardrailViolation::block('parallel_probe', 'expected no recorded steps before validating index 1');
        }
    }
}
