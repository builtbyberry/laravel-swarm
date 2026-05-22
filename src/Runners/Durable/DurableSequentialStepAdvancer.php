<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Memory\MemoryReplayCoordinator;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;

/**
 * @internal
 */
class DurableSequentialStepAdvancer
{
    public function __construct(
        protected SequentialRunner $sequential,
        protected MemoryReplayCoordinator $coordinator,
    ) {}

    public function advance(SwarmExecutionState $state, int $expectedStepIndex): SwarmStep
    {
        /** @var SwarmStep */
        return $this->coordinator->during(
            $state->swarm::class,
            $state->context->runId,
            $expectedStepIndex,
            fn (?MemorySnapshot $existing) => $this->sequential->runSingleStep($state, $expectedStepIndex),
        );
    }
}
