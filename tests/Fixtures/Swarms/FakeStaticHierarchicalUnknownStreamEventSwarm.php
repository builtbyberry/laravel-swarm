<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\UnknownEventStreamEditor;

/**
 * Static hierarchical swarm whose single worker streams an unrecognized event —
 * exercises the StaticHierarchicalStreamRunner breadcrumb else, the twin of
 * {@see FakeUnknownStreamEventSwarm} for chain-parity.
 */
#[Topology(TopologyEnum::StaticHierarchical)]
class FakeStaticHierarchicalUnknownStreamEventSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new UnknownEventStreamEditor,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'editor_node',
            'nodes' => [
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => UnknownEventStreamEditor::class,
                    'prompt' => 'stream-edit-task',
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
