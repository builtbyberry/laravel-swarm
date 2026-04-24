<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

class HierarchicalWorkerNode extends HierarchicalRouteNode
{
    /**
     * @param  array<string, string>  $withOutputs
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $id,
        public readonly string $agentClass,
        public readonly string $prompt,
        public readonly array $withOutputs = [],
        array $metadata = [],
        public readonly ?string $next = null,
    ) {
        parent::__construct($id, 'worker', $metadata);
    }

    public function controlEdges(): array
    {
        return $this->next !== null ? [$this->next] : [];
    }
}
