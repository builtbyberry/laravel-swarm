<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

class HierarchicalFinishNode extends HierarchicalRouteNode
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $id,
        public readonly ?string $output = null,
        public readonly ?string $outputFrom = null,
        array $metadata = [],
    ) {
        parent::__construct($id, 'finish', $metadata);
    }

    public function controlEdges(): array
    {
        return [];
    }
}
