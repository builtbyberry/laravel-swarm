<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

abstract class HierarchicalRouteNode
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<int, string>
     */
    abstract public function controlEdges(): array;
}
