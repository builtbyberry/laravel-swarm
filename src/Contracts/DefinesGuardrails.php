<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Optional contract for swarms that declare guardrails.
 *
 * Return class names (resolved via the container) or pre-built instances.
 *
 * @phpstan-type GuardrailRef object|class-string
 */
interface DefinesGuardrails
{
    /**
     * @return array<int, GuardrailRef>
     */
    public function guardrails(): array;
}
