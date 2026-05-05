<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

final class HierarchicalTestPlan
{
    /**
     * @param  array<string, mixed>  $nodes
     * @return array{start_at: string, nodes: array<string, mixed>}
     */
    public static function make(string $startAt, array $nodes): array
    {
        return [
            'start_at' => $startAt,
            'nodes' => $nodes,
        ];
    }
}
