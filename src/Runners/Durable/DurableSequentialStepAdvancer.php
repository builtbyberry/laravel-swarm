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
     * When the run's pinned `#[DurableStreaming]` opt-in is on (#298/#310) the node
     * instead streams its events into the causal log. `$durableStreaming` is the
     * value pinned on the durable run row at run-start, threaded in by the caller
     * (like `$attemptEpoch` = recovery count) so a resume reads the run's original
     * decision rather than live config.
     *
     * Integrity vs emission are split (#310 KS1): whenever the opt-in is on
     * ({@see DurableNodeStreamRecorder::enabled()}) the crashed prior attempt is
     * retracted first (F2/F3, under the lease this call already holds — F5),
     * regardless of the operator kill-switch. Only then does the kill-switch
     * ({@see DurableNodeStreamRecorder::streamingActive()}) decide whether this
     * attempt emits via the sink or falls back to `prompt()` — so an operator can
     * shed the per-event write load without ever dropping a retraction (the seal
     * still fires at checkpoint). Either path runs inside the replay coordinator so
     * a resume replays byte-identically.
     */
    public function advance(SwarmExecutionState $state, int $expectedStepIndex, int $attemptEpoch = 0, bool $durableStreaming = false): SwarmStep
    {
        $runId = $state->context->runId;

        // Unpinned run: the unchanged blocking path — no void, no seal, no stream.
        if (! $this->nodeStream->enabled($durableStreaming)) {
            return $this->runBlocking($state, $expectedStepIndex);
        }

        // Sequential durable nodes are addressed as `step:{index}`, mirroring the
        // run store's nodeIdForRun(); the void query and the per-event stamp both
        // use this value, so they agree by construction.
        $nodeId = 'step:'.$expectedStepIndex;

        // Integrity first: retract a crashed prior attempt even when the kill-switch
        // has paused emission, so the fold never shows an orphaned attempt.
        $this->nodeStream->voidPriorAttempt($runId, $nodeId, $attemptEpoch, $durableStreaming);

        // Kill-switch paused emission: run blocking. The boundary is still sealed at
        // checkpoint (enabled-gated), so this node's (empty) window is fenced.
        if (! $this->nodeStream->streamingActive($durableStreaming)) {
            return $this->runBlocking($state, $expectedStepIndex);
        }

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

    private function runBlocking(SwarmExecutionState $state, int $expectedStepIndex): SwarmStep
    {
        /** @var SwarmStep */
        return $this->coordinator->during(
            $state->swarm::class,
            $state->context->runId,
            $expectedStepIndex,
            fn (?MemorySnapshot $existing) => $this->sequential->runSingleStep($state, $expectedStepIndex),
            $state->context,
        );
    }
}
