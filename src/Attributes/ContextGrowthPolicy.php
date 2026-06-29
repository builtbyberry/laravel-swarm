<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;
use BuiltByBerry\LaravelSwarm\Enums\GrowthPolicy;

/**
 * Declare a swarm's context-growth intent (#288).
 *
 * Governs how the framework reacts when a streaming run's hot working set
 * exceeds the operator-supplied budget. Applies to the streaming substrate
 * only — non-streaming prompt() runs do not accumulate a hot causal log and
 * are out of this policy's scope.
 *
 * When absent, the framework default is read from `swarm.context_growth.policy`
 * (itself defaulting to warn + degrade-to-cold).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ContextGrowthPolicy
{
    public function __construct(public GrowthPolicy $policy) {}
}
