<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

/**
 * Capability marker that keeps native-input continuations away from workers
 * which predate the operational-envelope reader.
 *
 * @internal
 */
class ResumeNativeInputQueuedHierarchicalSwarm extends ResumeQueuedHierarchicalSwarm {}
