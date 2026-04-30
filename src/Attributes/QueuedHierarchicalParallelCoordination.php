<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class QueuedHierarchicalParallelCoordination
{
    /**
     * @param  'in_process'|'multi_worker'  $coordination
     */
    public function __construct(public string $coordination = 'multi_worker') {}
}
