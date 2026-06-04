<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

/**
 * The concrete `(scope, scopeId)` address a memory tool resolved for the active
 * run, produced by {@see MemoryToolScopeResolver}.
 *
 * @internal
 */
final class ResolvedMemoryScope
{
    public function __construct(
        public readonly MemoryScope $scope,
        public readonly string $scopeId,
    ) {}
}
