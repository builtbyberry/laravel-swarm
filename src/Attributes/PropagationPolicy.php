<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;

/**
 * Override the memory propagation policy for a single swarm.
 *
 * Apply to a Swarm class to decide which memory entries its workers see at
 * invocation, overriding the global `swarm.memory.propagation_policy` config.
 *
 * ```php
 * #[PropagationPolicy(SharedConversationPolicy::class)]
 * class MySwarm implements Swarm { ... }
 * ```
 *
 * Unlike the enum-carrying attributes in this package (`#[Topology]`,
 * `#[MemoryReplay]`), this attribute carries a class-string because propagation
 * policies are open-ended application classes. The class is resolved through
 * the container, so policies may declare their own dependencies.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PropagationPolicy
{
    /**
     * @param  class-string<MemoryPropagationPolicy>  $policy
     */
    public function __construct(
        public readonly string $policy,
    ) {}
}
