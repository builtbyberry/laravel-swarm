<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Default {@see MemoryCapturePolicy}: writes every entry as-is.
 *
 * Returns {@see CaptureDecision::Full} unconditionally, so the bound
 * {@see RedactingMemoryStore} passes each entry straight through to the
 * underlying driver. This preserves pre-v0.10 behaviour exactly — memory
 * writes are byte-identical to what they were before this contract existed.
 * Applications opt in to redaction by binding their own policy.
 *
 * @internal
 */
final class DefaultMemoryCapturePolicy implements MemoryCapturePolicy
{
    public function memory(
        MemoryScope $scope,
        string $key,
        ?RunContext $context = null,
        ?Actor $actor = null,
    ): CaptureDecision {
        return CaptureDecision::Full;
    }
}
