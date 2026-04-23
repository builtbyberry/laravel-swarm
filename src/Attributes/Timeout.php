<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Timeout
{
    /**
     * @param  int  $seconds  Best-effort orchestration deadline checked before and between swarm steps.
     */
    public function __construct(public int $seconds) {}
}
