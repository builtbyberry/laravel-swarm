<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

/**
 * Canonical names for the package-reserved Run-scoped memory keys.
 *
 * Step outputs are persisted to Run scope under `swarm:step.{n}.output` so a
 * run accumulates a turn-by-turn record (see the `RemembersRunContext` trait
 * and `ConversationPropagationPolicy`). These keys are reserved: the
 * {@see DefaultPropagationPolicy} excludes them so non-trait agents see no
 * change, and {@see ConversationPropagationPolicy} surfaces them in order.
 *
 * This is the single source of truth for the key shape — the recorder writes
 * through it, both policies match through it, and tests assert through it.
 *
 * @internal
 */
final class SwarmMemoryKeys
{
    /**
     * Prefix marking a key as package-reserved. Application code must not write
     * keys under this prefix; the propagation policies treat them specially.
     */
    public const RESERVED_PREFIX = 'swarm:';

    /**
     * The Run-scoped key under which step `$index`'s output is persisted.
     */
    public static function stepOutput(int $index): string
    {
        return "swarm:step.{$index}.output";
    }

    /**
     * Whether `$key` is a step-output key produced by {@see stepOutput()}.
     */
    public static function isStepOutput(string $key): bool
    {
        return preg_match('/^swarm:step\.\d+\.output$/', $key) === 1;
    }

    /**
     * The step index encoded in a step-output key, or null when `$key` is not
     * a step-output key. Used to order the transcript by step.
     */
    public static function stepIndexOf(string $key): ?int
    {
        if (preg_match('/^swarm:step\.(\d+)\.output$/', $key, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
