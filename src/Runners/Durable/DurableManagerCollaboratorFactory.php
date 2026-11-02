<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Memory\MemoryReplayCoordinator;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalRunner;
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
 *   runContext → payloads → branches / joinRelease → signalHandler / retryHandler → inspector → recorder → rest
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
        StaticHierarchicalRunner $staticHierarchicalRunner,
        SwarmStepRecorder $stepsRecorder,
        Connection $connection,
        SwarmCapture $capture,
        SwarmPayloadLimits $limits,
        Application $application,
    ): DurableManagerCollaborators {
        $outbox = $this->application->make(DurableOutbox::class);
        $jobs = $this->application->makeWith(DurableJobDispatcher::class, [
            'config' => $config,
        ]);
        // db.transactions is resolved per push, not here: the testing traits rebind
        // it after the durable graph is built.
        $eager = $this->application->makeWith(DurableEagerDispatcher::class, [
            'connection' => $connection,
            'config' => $config,
            'jobs' => $jobs,
            'outbox' => $outbox,
            'container' => $this->application,
        ]);
        $admission = $this->application->makeWith(DurableBranchAdmission::class, [
            'config' => $config,
            'durableRuns' => $durableRuns,
            'outbox' => $outbox,
            'connection' => $connection,
            'eager' => $eager,
            'container' => $this->application,
        ]);

        $runContext = $this->application->makeWith(DurableRunContext::class, [
            'config' => $config,
            'durableRuns' => $durableRuns,
            'contextStore' => $contextStore,
            'historyStore' => $historyStore,
        ]);
        $payloads = $this->application->makeWith(DurablePayloadCapture::class, [
            'capture' => $capture,
        ]);
        $branches = $this->application->makeWith(DurableBranchCoordinator::class, [
            'config' => $config,
        ]);
        // Built before every wait closer and the hierarchical coordinator, so they all
        // share one instance: it is the one way a run waiting at a branch boundary is
        // released to its join.
        $joinRelease = $this->application->makeWith(DurableJoinRelease::class, [
            'durableRuns' => $durableRuns,
            'runs' => $runContext,
            'branches' => $branches,
            'outbox' => $outbox,
            'eager' => $eager,
            'connection' => $connection,
            'container' => $this->application,
        ]);
        $signalHandler = $this->application->makeWith(DurableSignalHandler::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'events' => $events,
            'capture' => $capture,
            'runs' => $runContext,
            'payloads' => $payloads,
            'connection' => $connection,
            'eager' => $eager,
            'joinRelease' => $joinRelease,
        ]);
        $retryHandler = $this->application->makeWith(DurableRetryHandler::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'connection' => $connection,
            'capture' => $capture,
            'runs' => $runContext,
            'eager' => $eager,
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
            'admission' => $admission,
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
            'eager' => $eager,
            'joinRelease' => $joinRelease,
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
            'connection' => $connection,
            'eager' => $eager,
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
            'eager' => $eager,
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
            'admission' => $admission,
            'connection' => $connection,
            'joinRelease' => $joinRelease,
        ]);
        $memoryReplayCoordinator = $this->application->make(MemoryReplayCoordinator::class);
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
            'coordinator' => $memoryReplayCoordinator,
            'eager' => $eager,
            'admission' => $admission,
            'joinRelease' => $joinRelease,
        ]);
        $staticHierarchical = $this->application->makeWith(DurableStaticHierarchicalCoordinator::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'connection' => $connection,
            'capture' => $capture,
            'application' => $application,
            'runs' => $runContext,
            'branches' => $branches,
            'hierarchical' => $staticHierarchicalRunner,
            'outbox' => $outbox,
            'coordinator' => $memoryReplayCoordinator,
            'eager' => $eager,
        ]);
        $terminal = $this->application->makeWith(DurableRunTerminalHandler::class, [
            'durableRuns' => $durableRuns,
            'events' => $events,
            'recorder' => $recorder,
            'capture' => $capture,
            'limits' => $limits,
            'runs' => $runContext,
            'payloads' => $payloads,
            'branches' => $branches,
            'children' => $children,
        ]);
        $parallel = $this->application->makeWith(DurableTopLevelParallelAdvancer::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'connection' => $connection,
            'capture' => $capture,
            'runs' => $runContext,
            'branches' => $branches,
            'terminal' => $terminal,
            'eager' => $eager,
        ]);
        $executionBuilder = $this->application->makeWith(DurableStepExecutionBuilder::class, [
            'durableRuns' => $durableRuns,
            'historyStore' => $historyStore,
            'contextStore' => $contextStore,
            'artifactRepository' => $artifactRepository,
            'events' => $events,
            'connection' => $connection,
            'capture' => $capture,
            'application' => $application,
            'runs' => $runContext,
            'payloads' => $payloads,
        ]);
        $sequentialAdvancer = $this->application->makeWith(DurableSequentialStepAdvancer::class, [
            'sequential' => $sequential,
            'coordinator' => $memoryReplayCoordinator,
            'nodeStream' => $this->application->make(DurableNodeStreamRecorder::class),
        ]);
        $checkpointCoordinator = $this->application->makeWith(DurableStepCheckpointCoordinator::class, [
            'durableRuns' => $durableRuns,
            'recorder' => $recorder,
            'hierarchical' => $hierarchical,
            'outbox' => $outbox,
            'eager' => $eager,
            'admission' => $admission,
        ]);
        $advancer = $this->application->makeWith(DurableStepAdvancer::class, [
            'durableRuns' => $durableRuns,
            'runs' => $runContext,
            'retryHandler' => $retryHandler,
            'hierarchical' => $hierarchical,
            'staticHierarchical' => $staticHierarchical,
            'terminal' => $terminal,
            'parallel' => $parallel,
            'executionBuilder' => $executionBuilder,
            'sequential' => $sequentialAdvancer,
            'checkpoints' => $checkpointCoordinator,
            'outbox' => $outbox,
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
            'outbox' => $outbox,
            'terminal' => $terminal,
            'coordinator' => $memoryReplayCoordinator,
            'nodeStream' => $this->application->make(DurableNodeStreamRecorder::class),
            'admission' => $admission,
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
            staticHierarchical: $staticHierarchical,
            advancer: $advancer,
            branchAdvancer: $branchAdvancer,
        );
    }
}
