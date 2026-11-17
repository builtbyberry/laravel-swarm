<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

use Laravel\Ai\Events\ToolFailed;

/**
 * What Swarm promises when a native tool's handler throws (#634).
 *
 * laravel/ai v1.4.0 changed this upstream: a throwing tool no longer ends the
 * run. `InvokesTools::executeTool()` wraps the handler's exception in an
 * internal `ToolExecutionException`, and `TextGenerationLoop` reports it and
 * returns "The tool call failed: <message>" to the model as a failed tool
 * result, so the agent can recover. On laravel/ai 1.0-1.3 the exception simply
 * propagated and the run failed.
 *
 * Swarm's documented contract is the pre-1.4 one — a tool call failure surfaces
 * at the agent-execution boundary (see docs/error-handling.md) — so
 * {@see self::Propagate} is the default and is preserved on every supported
 * laravel/ai version. {@see self::Recover} defers to laravel/ai instead.
 *
 * Propagation is implemented by a wildcard {@see ToolFailed} listener that
 * rethrows the handler's own exception from inside laravel/ai's catch block,
 * which is the exact program point where 1.0-1.3 failed. It therefore holds only
 * when that dispatch reaches Swarm's listener; the preconditions and the four
 * ways an application can bypass them are documented in docs/error-handling.md
 * and UPGRADING.md.
 */
enum NativeToolFailurePolicy: string
{
    /**
     * A failing native tool fails the run, rethrowing the handler's own
     * exception (default). Swarm's documented contract, on every supported
     * laravel/ai version.
     */
    case Propagate = 'propagate';

    /**
     * Defer to laravel/ai. On >= 1.4 the failure is reported and returned to the
     * model as a failed tool result and the run continues. On 1.0-1.3 laravel/ai
     * has no such recovery, so this is indistinguishable from
     * {@see self::Propagate} — Swarm does not synthesise it.
     */
    case Recover = 'recover';

    /**
     * Parse an operator-configured value, defaulting to {@see self::Propagate}.
     * Mirrors {@see GrowthPolicy::parse()} and
     * {@see PersistenceDecryptFailurePolicy::parse()}: a null/blank value is the
     * default (not invalid); an unrecognised value falls back to the default and
     * is flagged invalid.
     *
     * Resolution via {@see tryFromConfig()} defaults silently, and deliberately
     * so: this is read from inside the tool-failure path, where throwing on a
     * typo'd config value would mask the tool's own exception with a ValueError.
     *
     * @return array{policy: self, invalid: bool}
     */
    public static function parse(?string $value): array
    {
        if ($value === null) {
            return ['policy' => self::Propagate, 'invalid' => false];
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return ['policy' => self::Propagate, 'invalid' => false];
        }

        $normalized = strtolower($trimmed);

        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return ['policy' => $case, 'invalid' => false];
            }
        }

        return ['policy' => self::Propagate, 'invalid' => true];
    }

    public static function tryFromConfig(?string $value): self
    {
        return self::parse($value)['policy'];
    }
}
