<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

enum CoordinationProfile: string
{
    /** Full per-step durable execution (default). */
    case StepDurable = 'step_durable';

    /** Queued hierarchical swarm: multi-worker parallel route segments only. */
    case QueueHierarchicalParallel = 'queue_hierarchical_parallel';
}
