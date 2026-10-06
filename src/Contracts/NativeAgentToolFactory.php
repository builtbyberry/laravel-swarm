<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\NativeAgentToolReference;

interface NativeAgentToolFactory
{
    /**
     * Expand a registered factory call into frozen, reconstructible tool references.
     *
     * @param  array<string, mixed>  $arguments
     * @return iterable<int, NativeAgentToolReference>
     */
    public function references(array $arguments): iterable;
}
