<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmStepRecorder;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;

/**
 * Builds the coherent durable manager subgraph.
 *
 * This factory is the single point of construction for the entire DurableSwarmManager
 * collaborator graph. It instantiates one DurableRunContext and one DurablePayloadCapture
 * and passes them into every collaborator in the following order:
 *
 *   runContext → payloads → signalHandler / retryHandler → inspector → recorder → rest
 *
 * Every service that belongs to DurableSwarmManager must receive its DurableRunContext
 * from this factory — never from the container — so that the whole graph shares a single
 * instance. DurableSignalHandler, DurableRetryHandler, DurableRunInspector, and
 * DurableRunRecorder must not be registered as container singletons.
 *
 * @internal
 */
class DurableManagerCollaboratorFactory
{
    public function __construct(
        protected Application $application,
    ) {}

    public function make(
        ConfigRepository $config,
        DurableRunStore $durableRuns,
        DatabaseRunHistoryStore $historyStore,
        ContextStore $contextStore,
        ArtifactRepository $artifactRepository,
        Dispatcher $events,
        SequentialRunner $sequential,
        HierarchicalRunner $hierarchicalRunner,
        SwarmStepRecorder $stepsRecorder,
        Connection $connection,
        SwarmCapture $capture,
        SwarmPayloadLimits $limits,
        Application $application,
    ): DurableManagerCollaborators {
        $runContext = $this->application->makeWith(DurableRunContext::class, [
            'config' => $config,
            'durableRuns' => $durableRuns,
            'contextStore' => $contextStore,
            'historyStore' => $historyStore,
        ]);
        $payloads = $this->application->makeWith(DurablePayloadCapture::class, [
            'capture' => $capture,
        ]);
        $signalHandler = $this->application->makeWith(DurableSignalHandler::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'events' => $events,
            'capture' => $capture,
            'runs' => $runContext,
            'payloads' => $payloads,
        ]);
        $retryHandler = $this->application->makeWith(DurableRetryHandler::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'connection' => $connection,
            'capture' => $capture,
            'runs' => $runContext,
        ]);
        $inspector = $this->application->makeWith(DurableRunInspector::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'events' => $events,
            'payloads' => $payloads,
            'runs' => $runContext,
        ]);
        $recorder = $this->application->makeWith(DurableRunRecorder::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'artifactRepository' => $artifactRepository,
            'connection' => $connection,
            'capture' => $capture,
            'runs' => $runContext,
        ]);
        $jobs = $this->application->makeWith(DurableJobDispatcher::class, [
            'config' => $config,
        ]);
        $branches = $this->application->makeWith(DurableBranchCoordinator::class, [
            'config' => $config,
        ]);
        $starter = $this->application->makeWith(DurableSwarmStarter::class, [
            'config' => $config,
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'connection' => $connection,
            'capture' => $capture,
            'limits' => $limits,
            'runs' => $runContext,
            'jobs' => $jobs,
        ]);
        $children = $this->application->makeWith(DurableChildSwarmCoordinator::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'events' => $events,
            'connection' => $connection,
            'capture' => $capture,
            'application' => $application,
            'runs' => $runContext,
            'payloads' => $payloads,
            'jobs' => $jobs,
        ]);
        $queuedHierarchical = $this->application->makeWith(QueuedHierarchicalDurableCoordinator::class, [
            'config' => $config,
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'capture' => $capture,
            'runs' => $runContext,
            'branches' => $branches,
            'jobs' => $jobs,
        ]);
        $boundary = $this->application->makeWith(DurableBoundaryCoordinator::class, [
            'durableRuns' => $durableRuns,
            'signals' => $signalHandler,
            'children' => $children,
        ]);
        $lifecycle = $this->application->makeWith(DurableLifecycleController::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'events' => $events,
            'connection' => $connection,
            'capture' => $capture,
            'runs' => $runContext,
            'payloads' => $payloads,
            'jobs' => $jobs,
            'children' => $children,
        ]);
        $recovery = $this->application->makeWith(DurableRecoveryCoordinator::class, [
            'config' => $config,
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'events' => $events,
            'capture' => $capture,
            'runs' => $runContext,
            'jobs' => $jobs,
            'children' => $children,
        ]);
        $hierarchical = $this->application->makeWith(DurableHierarchicalCoordinator::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'connection' => $connection,
            'capture' => $capture,
            'application' => $application,
            'runs' => $runContext,
            'branches' => $branches,
            'hierarchical' => $hierarchicalRunner,
            'jobs' => $jobs,
        ]);
        $advancer = $this->application->makeWith(DurableStepAdvancer::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'artifactRepository' => $artifactRepository,
            'events' => $events,
            'sequential' => $sequential,
            'recorder' => $recorder,
            'connection' => $connection,
            'capture' => $capture,
            'limits' => $limits,
            'application' => $application,
            'runs' => $runContext,
            'payloads' => $payloads,
            'branches' => $branches,
            'children' => $children,
            'retryHandler' => $retryHandler,
            'hierarchical' => $hierarchical,
        ]);
        $branchAdvancer = $this->application->makeWith(DurableBranchAdvancer::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'artifactRepository' => $artifactRepository,
            'events' => $events,
            'stepsRecorder' => $stepsRecorder,
            'connection' => $connection,
            'capture' => $capture,
            'application' => $application,
            'runs' => $runContext,
            'branches' => $branches,
            'hierarchical' => $hierarchical,
            'retryHandler' => $retryHandler,
        ]);

        return new DurableManagerCollaborators(
            runContext: $runContext,
            payloads: $payloads,
            signalHandler: $signalHandler,
            retryHandler: $retryHandler,
            inspector: $inspector,
            recorder: $recorder,
            jobs: $jobs,
            starter: $starter,
            queuedHierarchical: $queuedHierarchical,
            boundary: $boundary,
            branches: $branches,
            children: $children,
            lifecycle: $lifecycle,
            recovery: $recovery,
            hierarchical: $hierarchical,
            advancer: $advancer,
            branchAdvancer: $branchAdvancer,
        );
    }
}
