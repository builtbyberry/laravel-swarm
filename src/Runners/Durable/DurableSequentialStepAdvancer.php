<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;

class DurableSequentialStepAdvancer
{
    public function __construct(
        protected SequentialRunner $sequential,
    ) {}

    public function advance(SwarmExecutionState $state, int $expectedStepIndex): SwarmStep
    {
        return $this->sequential->runSingleStep($state, $expectedStepIndex);
    }
}
