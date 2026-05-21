<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Swarms\SequentialBlogPipeline;

use {{ rootNamespace }}\Ai\Agents\SequentialBlogPipeline\Drafter;
use {{ rootNamespace }}\Ai\Agents\SequentialBlogPipeline\OutlineWriter;
use {{ rootNamespace }}\Ai\Agents\SequentialBlogPipeline\Polisher;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

/**
 * sequential-blog-pipeline — the "hello world" of Laravel Swarm.
 *
 * Topology: Sequential. Three agents run in order. Each agent's reply becomes
 * the next agent's prompt. Default execution mode is in-memory `prompt()`;
 * no queue worker, no database, no audit sink.
 *
 * Demonstrates: agents(), Sequential topology, Runnable trait, plain-data
 * task input, accessing $response->output and $response->steps after a run.
 *
 * Next step: docs/sequential.md, docs/execution-modes.md
 */
#[Topology(TopologyEnum::Sequential)]
class BlogPipeline implements Swarm
{
    use Runnable;

    /**
     * @return array<int, \BuiltByBerry\LaravelSwarm\Contracts\Agent>
     */
    public function agents(): array
    {
        return [
            new OutlineWriter,
            new Drafter,
            new Polisher,
        ];
    }
}
