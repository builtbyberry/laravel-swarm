<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

class DurableManagerCollaborators
{
    public function __construct(
        public DurableRunContext $runContext,
        public DurablePayloadCapture $payloads,
        public DurableJobDispatcher $jobs,
        public DurableSwarmStarter $starter,
        public QueuedHierarchicalDurableCoordinator $queuedHierarchical,
        public DurableBoundaryCoordinator $boundary,
        public DurableBranchCoordinator $branches,
        public DurableChildSwarmCoordinator $children,
        public DurableLifecycleController $lifecycle,
        public DurableRecoveryCoordinator $recovery,
        public DurableHierarchicalCoordinator $hierarchical,
        public DurableStepAdvancer $advancer,
        public DurableBranchAdvancer $branchAdvancer,
    ) {}
}
