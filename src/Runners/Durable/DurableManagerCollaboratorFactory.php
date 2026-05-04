<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
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
        Dispatcher $events,
        Connection $connection,
        SwarmCapture $capture,
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

        return new DurableManagerCollaborators(
            runContext: $runContext,
            payloads: $payloads,
            jobs: $jobs,
            branches: $branches,
            children: $children,
            lifecycle: $lifecycle,
            recovery: $recovery,
        );
    }
}
