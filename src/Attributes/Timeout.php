<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Timeout
{
    /**
     * @param  int  $seconds  Wall-clock timeout for the entire swarm run.
     */
    public function __construct(public int $seconds) {}
}
