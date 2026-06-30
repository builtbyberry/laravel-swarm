<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RichStreamEditor;

/**
 * A static-hierarchical swarm with a parallel fan-out (`research_node` + `write_node`)
 * that joins at a synthesis worker — the #312 hierarchical-fan-out per-branch streaming
 * scenario. The two fan-out branches run as independent durable jobs through
 * DurableBranchAdvancer; each persists a NON-null node_id, so its streamed events are
 * stamped with that node_id (not the branch_id fallback). The branch generation is
 * sealed by the parent's post-join checkpoint, never at branch commit (gate H2).
 *
 * The two branch workers are distinct streaming agent classes so their event uuids
 * never collide. The synthesis node runs blocking (hierarchical main-walk streaming is
 * #311's scope); its post-join checkpoint is where this swarm's branch seal lands.
 *
 * Intentionally NOT carrying #[DurableStreaming]: the dispatch-time allow-list only
 * admits StaticHierarchical once #311 lands, so an attributed dispatch would fail loud.
 * The fan-out branch streaming + seal-on-join wiring is topology-agnostic and lives in
 * #312's DurableBranchAdvancer / checkpointHierarchical; the test exercises it by pinning
 * `durable_streaming = true` on the run row directly, which is exactly what #311's
 * allow-list entry will do at dispatch.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
class HierarchicalFanOutStreamingSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new PlainStreamEditor('research'),
            new RichStreamEditor,
            new FakeEditor,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'fan_out',
            'nodes' => [
                'fan_out' => [
                    'type' => 'parallel',
                    'branches' => ['research_node', 'write_node'],
                    'next' => 'synthesis_node',
                ],
                'research_node' => [
                    'type' => 'worker',
                    'agent' => PlainStreamEditor::class,
                    'prompt' => 'fan-out-research-task',
                ],
                'write_node' => [
                    'type' => 'worker',
                    'agent' => RichStreamEditor::class,
                    'prompt' => 'fan-out-write-task',
                ],
                'synthesis_node' => [
                    'type' => 'worker',
                    'agent' => FakeEditor::class,
                    'prompt' => 'fan-out-synthesis-task',
                    'with_outputs' => [
                        'research' => 'research_node',
                        'draft' => 'write_node',
                    ],
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'synthesis_node',
                ],
            ],
        ];
    }
}
