<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Swarms\DurableStreamingDigest;

use {{ rootNamespace }}\Ai\Agents\DurableStreamingDigest\ScriptedStreamingEditor;
use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

/**
 * durable-streaming-digest — per-node durable streaming, end to end.
 *
 * Topology: Sequential, executed in **durable** mode with per-node streaming
 * turned on by the `#[DurableStreaming]` attribute. Two worker nodes each
 * token-stream a section of a digest; every token delta is persisted to the
 * causal log under that node's id, so the run can be replayed and the streamed
 * text reconstructed after the fact — even across a worker crash.
 *
 * Flow:
 *   1. `dispatchDurable()` enqueues the run and pins `durable_streaming = true`
 *      on the run row (resolved from the attribute at run-start).
 *   2. Step 0 (`step:0`) streams the headline section token-by-token; each
 *      `swarm_text_delta` is checkpointed into `swarm_stream_events`.
 *   3. Step 1 (`step:1`) streams the summary section the same way.
 *   4. The run completes. `CausalLogView::forRun(...)->fold()` reads the
 *      persisted deltas back, per node, in causal order.
 *
 * Why durable streaming (not live `->stream()`): only a durable dispatch
 * persists per-node deltas. A live `->run()` / `->stream()` carrying this same
 * attribute would stream to the caller but write **zero** durable rows — nothing
 * to replay. The runnable command in this example dispatches durably for that
 * reason; see its docblock and the README.
 *
 * Requires (real-app run):
 *   - SWARM_PERSISTENCE_DRIVER=database (the array/in-memory driver has no
 *     `swarm_stream_events` table; dispatch fails loud without it)
 *   - package migrations have run
 *   - a queue worker on the durable connection to advance the run
 *
 * Next step: docs/streaming-substrate-author-guide.md, docs/durable-execution.md
 */
#[Topology(TopologyEnum::Sequential)]
#[DurableStreaming]
class StreamingDigestSwarm implements Swarm
{
    use Runnable;

    /**
     * @return array<int, \BuiltByBerry\LaravelSwarm\Contracts\Agent>
     */
    public function agents(): array
    {
        return [
            new ScriptedStreamingEditor('headline', ['This ', 'week ', 'in ', 'engineering: ']),
            new ScriptedStreamingEditor('summary', ['Three ', 'PRs ', 'merged, ', 'zero ', 'incidents.']),
        ];
    }
}
