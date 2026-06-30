<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;

/**
 * Mutable per-step carrier threaded through the vendor → swarm stream-event
 * mapping (#298).
 *
 * Mapping a single provider event has side effects beyond producing the swarm
 * event: it accumulates the agent's output text, pairs tool calls with their
 * results into the frozen snapshot, records the final step usage, and notes any
 * unrecognized event class for the breadcrumb. This object holds exactly that
 * state so the mapping lives in one place ({@see SequentialRunner::mapStreamEvent()})
 * and both the live `stream()` loop and the durable per-node `streamSingleStep()`
 * fold a step's events identically — no second copy of the mapping to drift.
 *
 * Generator-local by construction: one instance per step execution, created in
 * the caller's scope and never held on a runner field. That keeps two concurrent
 * runs in one Octane worker from sharing a step's pending tool calls or output.
 *
 * @internal
 */
final class StreamStepAccumulator
{
    /**
     * The agent's raw (un-captured) output text, concatenated from text deltas.
     * This is the value the {@see SwarmStep}
     * records, mirroring the blocking `prompt()` path's `(string) $response`.
     */
    public string $output = '';

    /**
     * Tool calls seen but not yet paired with their result. A call is held here
     * from its `ToolCall` event until its matching `ToolResult` finalizes the
     * snapshot entry; whatever remains at stream end is flushed unpaired so an
     * abandoned (crashed) stream still records every tool the agent invoked.
     *
     * @var array<string, ToolCallData>
     */
    public array $pendingToolCalls = [];

    /**
     * The step's token usage, set from the terminal `StreamEnd` event's
     * `Usage::toArray()`. Empty when the stream was abandoned before `StreamEnd`
     * (a crash mid-node).
     *
     * @var array<string, int>
     */
    public array $stepUsage = [];

    /**
     * Distinct provider event classes the mapping did not recognize, keyed by
     * class for dedupe. Drives one breadcrumb per step — class names only, never
     * the event body (it may carry un-redacted content).
     *
     * @var array<string, true>
     */
    public array $unknownEventClasses = [];

    public function __construct(public MemorySnapshot $snapshot) {}
}
