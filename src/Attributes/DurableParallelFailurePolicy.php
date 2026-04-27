<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy as DurableParallelFailurePolicyEnum;

#[Attribute(Attribute::TARGET_CLASS)]
final class DurableParallelFailurePolicy
{
    /**
     * @param  DurableParallelFailurePolicyEnum  $policy  Failure behavior for durable parallel branch joins.
     */
    public function __construct(public DurableParallelFailurePolicyEnum $policy) {}
}
