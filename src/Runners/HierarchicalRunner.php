<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;

/**
 * Hierarchical topology: delegates to sequential execution until coordinator routing is implemented.
 *
 * @todo Support coordinator structured routing to invoke downstream agents with custom inputs.
 */
class HierarchicalRunner
{
    public function __construct(
        protected SequentialRunner $sequential,
    ) {}

    public function run(SwarmExecutionState $state): SwarmResponse
    {
        return $this->sequential->run($state);
    }
}
