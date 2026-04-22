<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;

#[Attribute(Attribute::TARGET_CLASS)]
class Execution
{
    public function __construct(
        public readonly ExecutionMode $mode,
    ) {}
}
