<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

#[Attribute(Attribute::TARGET_CLASS)]
final class MaxAgentSteps
{
    /**
     * @param  int  $steps  Maximum number of agent executions per swarm run.
     */
    public function __construct(public int $steps)
    {
        if ($steps <= 0) {
            throw new SwarmException('Swarm max agent steps must be a positive integer.');
        }
    }
}
