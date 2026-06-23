<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ZdrStreamEditor;

/**
 * StaticHierarchical twin of {@see FakeZdrStreamingSwarm}. Drives the ZDR
 * (null-summary + encrypted-tool-call) shape through StaticHierarchicalStreamRunner
 * — whose reasoning/tool-call capture helpers are distinct from the sequential
 * runner's — so the F4 invariant is verified on both runner paths, not one.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
class FakeStaticHierarchicalZdrSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ZdrStreamEditor,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'editor_node',
            'nodes' => [
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => ZdrStreamEditor::class,
                    'prompt' => 'zdr-task',
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
