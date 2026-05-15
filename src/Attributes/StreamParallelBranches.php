<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class StreamParallelBranches
{
    /**
     * @param  'concurrent'|'sequential'  $mode
     */
    public function __construct(public readonly string $mode = 'concurrent') {}
}
