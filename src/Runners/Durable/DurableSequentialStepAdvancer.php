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
        protected DurableNodeStreamRecorder $nodeStream,
    ) {}

    /**
     * Run one durable sequential step.
     *
     * Blocking by default: the agent's `prompt()` produces one response per node.
     * When per-node streaming is enabled (#298) the node instead streams its events
     * into the causal log: the crashed prior attempt is retracted first (F2/F3,
     * under the lease this call already holds — F5), then this attempt's events are
     * appended stamped with the node id and `$attemptEpoch` via a fresh per-call
     * sink closure (F4 — no per-attempt state on this advancer). Either way the step
     * runs inside the replay coordinator so a resume replays byte-identically.
     */
    public function advance(SwarmExecutionState $state, int $expectedStepIndex, int $attemptEpoch = 0): SwarmStep
    {
        if (! $this->nodeStream->enabled()) {
            /** @var SwarmStep */
            return $this->coordinator->during(
                $state->swarm::class,
                $state->context->runId,
                $expectedStepIndex,
                fn (?MemorySnapshot $existing) => $this->sequential->runSingleStep($state, $expectedStepIndex),
                $state->context,
            );
        }

        $runId = $state->context->runId;
        // Sequential durable nodes are addressed as `step:{index}`, mirroring the
        // run store's nodeIdForRun(); the void query and the per-event stamp both
        // use this value, so they agree by construction.
        $nodeId = 'step:'.$expectedStepIndex;

        $this->nodeStream->voidPriorAttempt($runId, $nodeId, $attemptEpoch);
        $sink = $this->nodeStream->sinkFor($runId, $nodeId, $attemptEpoch);

        /** @var SwarmStep */
        return $this->coordinator->during(
            $state->swarm::class,
            $runId,
            $expectedStepIndex,
            fn (?MemorySnapshot $existing) => $this->sequential->streamSingleStep($state, $expectedStepIndex, $sink),
            $state->context,
        );
    }
}
