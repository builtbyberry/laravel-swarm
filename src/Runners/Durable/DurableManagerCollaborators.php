<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;

/**
 * Value object returned by DurableManagerCollaboratorFactory::make().
 *
 * Every property is assigned exactly once at construction time. All collaborators
 * in this bag share the same DurableRunContext and DurablePayloadCapture instances
 * that the factory built at the top of its make() method.
 *
 * @internal
 */
class DurableManagerCollaborators
{
    public function __construct(
        public DurableRunContext $runContext,
        public DurablePayloadCapture $payloads,
        public DurableSignalHandler $signalHandler,
        public DurableRetryHandler $retryHandler,
        public DurableRunInspector $inspector,
        public DurableRunRecorder $recorder,
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
