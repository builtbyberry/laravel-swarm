<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Routing;

/**
 * @internal
 */
class HierarchicalWorkerNode extends HierarchicalRouteNode
{
    /**
     * @param  class-string  $agentClass
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
        public readonly ?string $loopTo = null,
        public readonly ?int $loopMaxIterations = null,
    ) {
        parent::__construct($id, 'worker', $metadata);
    }

    public function hasLoop(): bool
    {
        return $this->loopTo !== null;
    }

    public function controlEdges(): array
    {
        return $this->next !== null ? [$this->next] : [];
    }

    /**
     * The loop back-edge, separated from forward control edges so cycle
     * detection can treat it as a bounded edge rather than a plain cycle.
     *
     * @return array<int, string>
     */
    public function loopEdges(): array
    {
        return $this->loopTo !== null ? [$this->loopTo] : [];
    }

    /**
     * Whether this worker's $iteration-th run routes back along its loop
     * back-edge rather than falling through to the forward exit. The single
     * authority for the loop-vs-fall-through decision across every runner.
     */
    public function loopsBackAt(int $iteration): bool
    {
        return $this->hasLoop() && $iteration < (int) $this->loopMaxIterations;
    }

    /**
     * The next node id after this worker completes its $iteration-th run: the
     * loop back-edge while still under the bound, otherwise the forward exit.
     */
    public function successor(int $iteration): ?string
    {
        return $this->loopsBackAt($iteration) ? $this->loopTo : $this->next;
    }
}
