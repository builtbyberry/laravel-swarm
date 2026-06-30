<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;

/**
 * A static-hierarchical swarm that opts into `#[DurableStreaming]` (#311). There is
 * no coordinator agent — the static plan IS the route — so durable step 0 is a pure
 * init step that builds the cursor without an agent call, and the worker nodes
 * (steps 1+) stream per-node into the causal log under their plan node ids. The
 * flaky worker crashes mid-node on its first attempt so the resume void/seal is
 * exercised; the retry attribute lets it re-execute after backoff.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
#[DurableRetry(maxAttempts: 2, backoffSeconds: [60])]
#[DurableStreaming]
class StaticHierarchicalDurableStreamingSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new PlainStreamEditor('writer'),
            new FlakyStreamEditor,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'writer_node',
            'nodes' => [
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => PlainStreamEditor::class,
                    'prompt' => 'static-writer-task',
                    'next' => 'editor_node',
                ],
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => FlakyStreamEditor::class,
                    'prompt' => 'static-editor-task',
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'editor_node',
                ],
            ],
        ];
    }
}
