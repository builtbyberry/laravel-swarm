<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;

/**
 * Controls how a swarm re-executes after a durable crash-resume.
 *
 * Apply to a Swarm class to override the global `swarm.memory.replay_mode` config.
 *
 * ```php
 * #[MemoryReplay(mode: ReplayMode::FreshExecution)]
 * class MySwarm extends Swarm { ... }
 * ```
 *
 * The default — and the mode used when this attribute is absent — is
 * {@see ReplayMode::FrozenView}: agents re-execute against the snapshot frozen
 * at the original invocation, so the determinism guarantee is preserved across
 * workers and crash-resume boundaries.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class MemoryReplay
{
    public function __construct(
        public readonly ReplayMode $mode = ReplayMode::FrozenView,
    ) {}
}
