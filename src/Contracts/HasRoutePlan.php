<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

interface HasRoutePlan
{
    /**
     * Return the static route plan for this swarm.
     * Must match the same shape as a hierarchical coordinator plan.
     *
     * @return array{start_at: string, nodes: array<string, array<string, mixed>>}
     */
    public function plan(): array;
}
