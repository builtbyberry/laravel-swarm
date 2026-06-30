<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;

/**
 * A top-level parallel durable swarm whose first branch crashes mid-stream on its
 * first attempt while the second branch streams cleanly — the #312 per-branch
 * streaming resume scenario. The two branches run as independent durable jobs into
 * one run-scoped causal log; resume must retract only the crashed branch's prior
 * attempt and leave the committed sibling untouched (no SealedCausalWindowException).
 *
 * Branch `parallel:0` is the flaky agent (shared static counter is fine — only this
 * branch increments it); branch `parallel:1` is a distinct-labelled clean agent so
 * its event uuids never collide with the flaky branch's.
 */
#[Topology(TopologyEnum::Parallel)]
#[DurableRetry(maxAttempts: 2, backoffSeconds: [60])]
#[DurableStreaming]
class ParallelDurableStreamingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FlakyStreamEditor,
            new PlainStreamEditor('sibling'),
        ];
    }
}
