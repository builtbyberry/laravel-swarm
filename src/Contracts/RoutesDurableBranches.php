<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\RunContext;

interface RoutesDurableBranches
{
    /**
     * @param  array<string, mixed>  $branch
     * @return array{connection?: string|null, queue?: string|null}
     */
    public function durableBranchQueue(RunContext $context, array $branch): array;
}
