<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

#[Attribute(Attribute::TARGET_CLASS)]
final class Timeout
{
    /**
     * @param  int  $seconds  Best-effort orchestration deadline checked before and between swarm steps.
     */
    public function __construct(public int $seconds)
    {
        if ($seconds <= 0) {
            throw new SwarmException('Swarm timeout must be a positive integer.');
        }
    }
}
