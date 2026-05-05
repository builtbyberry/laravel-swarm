<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Attributes\DurableDetails;
use BuiltByBerry\LaravelSwarm\Attributes\DurableLabels;
use BuiltByBerry\LaravelSwarm\Attributes\DurableWait;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBoundaryCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRunInspector;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSwarmStarter;
use BuiltByBerry\LaravelSwarm\Runners\Durable\QueuedHierarchicalDurableCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueueHierarchicalParallelBoundary;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Foundation\Bus\PendingDispatch;

#[DurableLabels(['tenant' => 'acme'])]
#[DurableDetails(['ticket_id' => 'TKT-1234'])]
class DurableStarterAttributedSwarm implements Swarm
{
    public function agents(): array
    {
        return [new FakeResearcher];
    }
}

#[DurableWait('approval_received', timeout: 60, reason: 'Waiting for approval')]
class DurableBoundaryWaitSwarm implements Swarm
{
    public function agents(): array
    {
        return [new FakeResearcher];
    }
}

function configureDurableCollaboratorRuntime(): void
{
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(DurableSwarmManager::class);
}

function durableCollaboratorNoopDispatch(): PendingDispatch
{
    return new class(new class
    {
        public function handle(): void {}
    }) extends PendingDispatch
    {

        public function __destruct() {}
    };
}

test('durable starter owns run creation metadata labels details and initial job routing', function () {
    configureDurableCollaboratorRuntime();

    $context = RunContext::fromTask('starter-task');
    $start = app(DurableSwarmStarter::class)->start(
        new DurableStarterAttributedSwarm,
        $context,
        Topology::Sequential,
        300,
        1,
    );

    $run = app(DurableRunStore::class)->find($start->runId);
    $detail = app(DurableRunInspector::class)->inspect($start->runId);

    expect($run['status'])->toBe('pending')
        ->and($run['queue_connection'])->toBe('durable-test')
        ->and($run['queue_name'])->toBe('swarm-durable')
        ->and($context->metadata['execution_mode'])->toBe(ExecutionMode::Durable->value)
        ->and($detail->labels)->toBe(['tenant' => 'acme'])
        ->and($detail->details)->toBe(['ticket_id' => 'TKT-1234'])
        ->and($start->job->runId)->toBe($start->runId);
});

test('durable boundary coordinator enters declared wait once and skips open waits', function () {
    configureDurableCollaboratorRuntime();

    $context = RunContext::fromTask('boundary-task');
    app(DurableSwarmStarter::class)->start(new DurableBoundaryWaitSwarm, $context, Topology::Sequential, 300, 1);
    $run = app(DurableRunStore::class)->find($context->runId);
    $coordinator = app(DurableBoundaryCoordinator::class);

    $entered = $coordinator->enterDeclaredBoundary(
        $run,
        new DurableBoundaryWaitSwarm,
        $context,
        fn (): PendingDispatch => durableCollaboratorNoopDispatch(),
    );
    $enteredAgain = $coordinator->enterDeclaredBoundary(
        app(DurableRunStore::class)->find($context->runId),
        new DurableBoundaryWaitSwarm,
        $context,
        fn (): PendingDispatch => durableCollaboratorNoopDispatch(),
    );

    $waits = app(DurableRunInspector::class)->inspect($context->runId)->waits;

    expect($entered)->toBeTrue()
        ->and($enteredAgain)->toBeFalse()
        ->and($waits)->toHaveCount(1)
        ->and($waits[0]['name'])->toBe('approval_received')
        ->and($waits[0]['status'])->toBe('waiting');
});

test('factory shares signal handler instance between manager and boundary coordinator', function () {
    configureDurableCollaboratorRuntime();

    $manager = app(DurableSwarmManager::class);

    $signalHandlerProp = new ReflectionProperty($manager, 'signalHandler');
    $signalHandlerInManager = $signalHandlerProp->getValue($manager);

    $boundaryProp = new ReflectionProperty($manager, 'boundary');
    $boundary = $boundaryProp->getValue($manager);

    $signalsProp = new ReflectionProperty($boundary, 'signals');

    expect($signalsProp->getValue($boundary))->toBe($signalHandlerInManager);
});

test('factory shares run context through extracted step advancement collaborators', function () {
    configureDurableCollaboratorRuntime();

    $manager = app(DurableSwarmManager::class);

    $hierarchicalProp = new ReflectionProperty($manager, 'hierarchicalCoordinator');
    $hierarchical = $hierarchicalProp->getValue($manager);

    $advancerProp = new ReflectionProperty($manager, 'advancer');
    $advancer = $advancerProp->getValue($manager);

    $runContextProp = new ReflectionProperty($advancer, 'runs');
    $runContext = $runContextProp->getValue($advancer);

    $terminalProp = new ReflectionProperty($advancer, 'terminal');
    $terminal = $terminalProp->getValue($advancer);

    $parallelProp = new ReflectionProperty($advancer, 'parallel');
    $parallel = $parallelProp->getValue($advancer);

    $executionBuilderProp = new ReflectionProperty($advancer, 'executionBuilder');
    $executionBuilder = $executionBuilderProp->getValue($advancer);

    $checkpointsProp = new ReflectionProperty($advancer, 'checkpoints');
    $checkpoints = $checkpointsProp->getValue($advancer);

    expect((new ReflectionProperty($terminal, 'runs'))->getValue($terminal))->toBe($runContext)
        ->and((new ReflectionProperty($parallel, 'runs'))->getValue($parallel))->toBe($runContext)
        ->and((new ReflectionProperty($executionBuilder, 'runs'))->getValue($executionBuilder))->toBe($runContext)
        ->and((new ReflectionProperty($parallel, 'terminal'))->getValue($parallel))->toBe($terminal)
        ->and((new ReflectionProperty($checkpoints, 'hierarchical'))->getValue($checkpoints))->toBe($hierarchical);
});

test('queued hierarchical durable coordinator creates coordination run and dispatches branches', function () {
    configureDurableCollaboratorRuntime();

    $dispatcher = new class(app('config')) extends DurableJobDispatcher
    {
        /** @var array<int, string> */
        public array $branchDispatches = [];

        public function dispatchBranch(string $runId, string $branchId, ?string $connection = null, ?string $queue = null): PendingDispatch
        {
            $this->branchDispatches[] = $branchId;

            return durableCollaboratorNoopDispatch();
        }
    };

    app()->instance(DurableJobDispatcher::class, $dispatcher);
    app()->forgetInstance(QueuedHierarchicalDurableCoordinator::class);

    $context = RunContext::fromTask('queued-hierarchical-task');
    $context->mergeMetadata([
        'swarm_class' => FakeSequentialSwarm::class,
        'topology' => Topology::Hierarchical->value,
    ]);
    $acquisition = app(DatabaseRunHistoryStore::class)->acquireQueuedRun($context->runId, FakeSequentialSwarm::class, Topology::Hierarchical->value, app(SwarmCapture::class)->context($context), $context->metadata, 3600, 600);
    app(ContextStore::class)->put(app(SwarmCapture::class)->activeContext($context), 3600);

    $state = new SwarmExecutionState(
        swarm: new FakeSequentialSwarm,
        topology: Topology::Hierarchical,
        executionMode: ExecutionMode::Queue,
        deadlineMonotonic: hrtime(true) + 300_000_000_000,
        maxAgentExecutions: 3,
        ttlSeconds: 3600,
        leaseSeconds: 600,
        executionToken: $acquisition->executionToken,
        verifyOwnership: null,
        context: $context,
        contextStore: app(ContextStore::class),
        artifactRepository: app(ArtifactRepository::class),
        historyStore: app(RunHistoryStore::class),
        events: app('events'),
        queueHierarchicalParallelCoordination: 'multi_worker',
    );

    app(QueuedHierarchicalDurableCoordinator::class)->enter($state, new QueueHierarchicalParallelBoundary(
        parentParallelNodeId: 'parallel_node',
        branchDefinitions: [
            ['branch_id' => 'parallel_node:writer_node', 'step_index' => 1, 'node_id' => 'writer_node', 'parent_node_id' => 'parallel_node', 'agent_class' => FakeWriter::class, 'input' => 'writer-task'],
        ],
        routeCursor: ['route_plan_start' => 'parallel_node'],
        routePlan: ['start_at' => 'parallel_node', 'nodes' => []],
        nextStepIndexAfterJoin: 2,
        totalSteps: 3,
        stepsSoFar: [new SwarmStep(FakeResearcher::class, 'input', 'output')],
        mergedUsage: [],
        executedNodeIds: ['parallel_node'],
        executedAgentClasses: [FakeResearcher::class],
        parallelGroups: [['node_id' => 'parallel_node', 'branches' => ['writer_node']]],
        nodeOutputs: [],
        coordinatorClass: FakeResearcher::class,
    ));

    $run = app(DurableRunStore::class)->find($context->runId);

    expect($run['status'])->toBe('waiting')
        ->and($run['coordination_profile'])->toBe('queue_hierarchical_parallel')
        ->and($dispatcher->branchDispatches)->toBe(['parallel_node:writer_node']);
});
