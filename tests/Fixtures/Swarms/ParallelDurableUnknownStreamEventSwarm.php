<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\UnknownEventStreamEditor;

/**
 * A top-level parallel durable streaming swarm whose first branch streams an
 * {@see UnknownEventStreamEditor} event the mapper's instanceof chain does not
 * recognize. The branch advancer must breadcrumb the dropped class exactly as the
 * sequential path does (#312 review F2) — a silent drop leaves the branch's frozen
 * snapshot (the durable replay source) incomplete with no trace.
 *
 * The sibling branch streams cleanly so the assertion can prove the breadcrumb is
 * branch-scoped, not a side effect of the whole run.
 */
#[Topology(TopologyEnum::Parallel)]
#[DurableStreaming]
class ParallelDurableUnknownStreamEventSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new UnknownEventStreamEditor,
            new PlainStreamEditor('sibling'),
        ];
    }
}
