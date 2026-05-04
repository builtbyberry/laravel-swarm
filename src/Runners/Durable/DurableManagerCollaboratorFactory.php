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
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;

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
        DurableRunRecorder $recorder,
        Connection $connection,
        SwarmCapture $capture,
        SwarmPayloadLimits $limits,
        Application $application,
        DurableRetryHandler $retryHandler,
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
        $jobs = $this->application->makeWith(DurableJobDispatcher::class, [
            'config' => $config,
        ]);
        $branches = $this->application->makeWith(DurableBranchCoordinator::class, [
            'config' => $config,
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

        return new DurableManagerCollaborators(
            runContext: $runContext,
            payloads: $payloads,
            jobs: $jobs,
            branches: $branches,
            children: $children,
            lifecycle: $lifecycle,
            recovery: $recovery,
            hierarchical: $hierarchical,
            advancer: $advancer,
        );
    }
}
