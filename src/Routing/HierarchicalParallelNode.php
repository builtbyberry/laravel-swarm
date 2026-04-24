<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

class HierarchicalParallelNode extends HierarchicalRouteNode
{
    /**
     * @param  array<int, string>  $branches
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $id,
        public readonly array $branches,
        array $metadata = [],
        public readonly ?string $next = null,
    ) {
        parent::__construct($id, 'parallel', $metadata);
    }

    public function controlEdges(): array
    {
        return [
            ...$this->branches,
            ...($this->next !== null ? [$this->next] : []),
        ];
    }
}
