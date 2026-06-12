<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmStepGuardrail;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;

/**
 * A non-throwing step guardrail that counts how many times it validates a
 * target step index. Used to prove the #202 skip path still runs step
 * guardrails on the rehydrated output: across a crash + resume the counter for
 * the skipped non-final step reaches 2 (once on the original run, once on the
 * resumed/skipped run), not 1.
 *
 * Non-throwing by design — a blocking guardrail would abort the original step
 * before it checkpoints, leaving nothing to skip on resume.
 */
final class CountingStepGuardrail implements SwarmStepGuardrail
{
    /** Validations observed for the target step index. Reset in the test. */
    public static int $validations = 0;

    public function __construct(private int $index = -1) {}

    public function validate(GuardrailStepContext $step): void
    {
        if ($step->stepIndex === $this->index) {
            self::$validations++;
        }
    }
}
