<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Attributes\DurableDetails;
use BuiltByBerry\LaravelSwarm\Attributes\DurableLabels;
use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\DurableWait;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ConfiguresDurableRetries;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableBranches;
use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableWaits;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;
use BuiltByBerry\LaravelSwarm\Responses\DurableRetryPolicy;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBoundaryCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRetryHandler;
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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRoutedParallelSwarm;
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
    );
    $enteredAgain = $coordinator->enterDeclaredBoundary(
        app(DurableRunStore::class)->find($context->runId),
        new DurableBoundaryWaitSwarm,
        $context,
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

    $outbox = new class($dispatcher) implements DurableOutbox
    {
        public function __construct(private readonly DurableJobDispatcher $jobs) {}

        public function enqueueStep(string $runId, int $stepIndex, ?string $connection, ?string $queue): void
        {
            $this->jobs->dispatchStep($runId, $stepIndex, $connection, $queue);
        }

        public function enqueueBranch(string $runId, string $branchId, ?string $connection, ?string $queue): void
        {
            $this->jobs->dispatchBranch($runId, $branchId, $connection, $queue);
        }

        public function enqueueQueuedResume(string $runId, ?string $connection, ?string $queue): void
        {
            $this->jobs->dispatchQueuedResumeById($runId, $connection, $queue);
        }

        public function drain(array $types = [], int $limit = 100): DrainResult
        {
            return new DrainResult(0, 0);
        }
    };

    app()->instance(DurableOutbox::class, $outbox);
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

// --- ConfiguresDurableRetries seam (#371) -----------------------------------
//
// The interface is the programmatic alternative to the #[DurableRetry] attribute.
// The interface branch in DurableRetryHandler::resolveRetryPolicy() is checked
// first for both the agent-level and swarm-level policy; these tests lock the
// full precedence chain: interface-agent > agent-attribute > interface-swarm >
// swarm-attribute.

#[DurableRetry(maxAttempts: 2, backoffSeconds: [5])]
class RetryInterfaceAgent {}

#[DurableRetry(maxAttempts: 3)]
class RetryAttributeOnlyAgent {}

class RetryPlainAgent {}

class ConfiguresDurableRetriesSwarm implements ConfiguresDurableRetries, Swarm
{
    public function agents(): array
    {
        return [new FakeResearcher];
    }

    public function durableRetryPolicy(): DurableRetryPolicy
    {
        return new DurableRetryPolicy(maxAttempts: 7, backoffSeconds: [1, 2, 3]);
    }

    public function durableAgentRetryPolicy(string $agentClass): ?DurableRetryPolicy
    {
        return $agentClass === RetryInterfaceAgent::class
            ? new DurableRetryPolicy(maxAttempts: 9)
            : null;
    }
}

#[DurableRetry(maxAttempts: 4)]
class DurableRetryAttributeSwarm implements Swarm
{
    public function agents(): array
    {
        return [new FakeResearcher];
    }
}

test('resolveRetryPolicy uses the ConfiguresDurableRetries agent policy over the agent DurableRetry attribute', function () {
    configureDurableCollaboratorRuntime();

    // RetryInterfaceAgent also carries #[DurableRetry(maxAttempts: 2)]; the interface must win.
    $policy = app(DurableRetryHandler::class)->resolveRetryPolicy(
        new ConfiguresDurableRetriesSwarm,
        RetryInterfaceAgent::class,
    );

    expect($policy)->not->toBeNull()
        ->and($policy->maxAttempts)->toBe(9);
});

test('resolveRetryPolicy falls through to the agent DurableRetry attribute when the interface returns null for that agent', function () {
    configureDurableCollaboratorRuntime();

    $policy = app(DurableRetryHandler::class)->resolveRetryPolicy(
        new ConfiguresDurableRetriesSwarm,
        RetryAttributeOnlyAgent::class,
    );

    expect($policy)->not->toBeNull()
        ->and($policy->maxAttempts)->toBe(3);
});

test('resolveRetryPolicy falls through to the swarm ConfiguresDurableRetries policy when no agent policy applies', function () {
    configureDurableCollaboratorRuntime();

    $policy = app(DurableRetryHandler::class)->resolveRetryPolicy(
        new ConfiguresDurableRetriesSwarm,
        RetryPlainAgent::class,
    );

    expect($policy)->not->toBeNull()
        ->and($policy->maxAttempts)->toBe(7)
        ->and($policy->backoffSeconds)->toBe([1, 2, 3]);
});

test('resolveRetryPolicy uses the swarm ConfiguresDurableRetries policy when no agent class is given', function () {
    configureDurableCollaboratorRuntime();

    $policy = app(DurableRetryHandler::class)->resolveRetryPolicy(new ConfiguresDurableRetriesSwarm);

    expect($policy?->maxAttempts)->toBe(7);
});

test('resolveRetryPolicy falls back to the swarm DurableRetry attribute when the swarm does not implement ConfiguresDurableRetries', function () {
    configureDurableCollaboratorRuntime();

    $policy = app(DurableRetryHandler::class)->resolveRetryPolicy(new DurableRetryAttributeSwarm);

    expect($policy?->maxAttempts)->toBe(4);
});

// --- RoutesDurableWaits seam (#372) -----------------------------------------
//
// The interface branch in DurableBoundaryCoordinator::declaredWaits() returns
// verbatim and short-circuits before the #[DurableWait] attribute reflection,
// and it receives the RunContext so declared waits can vary per run.

#[DurableWait('attribute_wait', timeout: 99)]
class RoutesDurableWaitsSwarm implements RoutesDurableWaits, Swarm
{
    public function agents(): array
    {
        return [new FakeResearcher];
    }

    public function durableWaits(RunContext $context): array
    {
        $name = is_string($context->metadata['wait_name'] ?? null)
            ? $context->metadata['wait_name']
            : 'interface_wait';

        return [[
            'name' => $name,
            'timeout' => 30,
            'reason' => 'declared via RoutesDurableWaits',
            'metadata' => ['source' => 'interface'],
        ]];
    }
}

test('durable boundary coordinator uses RoutesDurableWaits and bypasses the DurableWait attribute', function () {
    configureDurableCollaboratorRuntime();

    $context = RunContext::fromTask('interface-wait-task');
    app(DurableSwarmStarter::class)->start(new RoutesDurableWaitsSwarm, $context, Topology::Sequential, 300, 1);
    $run = app(DurableRunStore::class)->find($context->runId);

    $entered = app(DurableBoundaryCoordinator::class)->enterDeclaredBoundary($run, new RoutesDurableWaitsSwarm, $context);
    $waits = app(DurableRunInspector::class)->inspect($context->runId)->waits;

    // The fixture also carries #[DurableWait('attribute_wait')]; the interface path wins.
    expect($entered)->toBeTrue()
        ->and($waits)->toHaveCount(1)
        ->and($waits[0]['name'])->toBe('interface_wait')
        ->and($waits[0]['status'])->toBe('waiting');
});

test('RoutesDurableWaits receives the RunContext so declared waits vary by context', function () {
    configureDurableCollaboratorRuntime();

    $contextAlpha = RunContext::fromTask('wait-alpha-task');
    $contextAlpha->mergeMetadata(['wait_name' => 'wait_alpha']);
    app(DurableSwarmStarter::class)->start(new RoutesDurableWaitsSwarm, $contextAlpha, Topology::Sequential, 300, 1);
    app(DurableBoundaryCoordinator::class)->enterDeclaredBoundary(
        app(DurableRunStore::class)->find($contextAlpha->runId),
        new RoutesDurableWaitsSwarm,
        $contextAlpha,
    );

    $contextBeta = RunContext::fromTask('wait-beta-task');
    $contextBeta->mergeMetadata(['wait_name' => 'wait_beta']);
    app(DurableSwarmStarter::class)->start(new RoutesDurableWaitsSwarm, $contextBeta, Topology::Sequential, 300, 1);
    app(DurableBoundaryCoordinator::class)->enterDeclaredBoundary(
        app(DurableRunStore::class)->find($contextBeta->runId),
        new RoutesDurableWaitsSwarm,
        $contextBeta,
    );

    $alphaWaits = app(DurableRunInspector::class)->inspect($contextAlpha->runId)->waits;
    $betaWaits = app(DurableRunInspector::class)->inspect($contextBeta->runId)->waits;

    expect($alphaWaits)->toHaveCount(1)
        ->and($betaWaits)->toHaveCount(1)
        ->and($alphaWaits[0]['name'])->toBe('wait_alpha')
        ->and($betaWaits[0]['name'])->toBe('wait_beta');
});

// --- RoutesDurableBranches seam (#373) --------------------------------------
//
// The interface branch in DurableBranchCoordinator::withBranchRouting() stamps
// the branch's queue_connection / queue_name — the exact fields the branch
// dispatch reads. It only overrides keys the interface actually returns, so an
// empty array falls back to the run's routing.

class EmptyRoutedBranchesSwarm implements RoutesDurableBranches, Swarm
{
    public function agents(): array
    {
        return [new FakeResearcher];
    }

    public function durableBranchQueue(RunContext $context, array $branch): array
    {
        return [];
    }
}

test('DurableBranchCoordinator stamps RoutesDurableBranches routing onto the dispatched branch', function () {
    config()->set('swarm.durable.parallel.queue.connection', null);
    config()->set('swarm.durable.parallel.queue.name', null);

    $branch = app(DurableBranchCoordinator::class)->withBranchRouting(
        new FakeRoutedParallelSwarm,
        RunContext::fromTask('branch-routing-task'),
        ['branch_id' => 'writer_branch', 'agent_class' => FakeWriter::class],
        ['queue_connection' => 'run-connection', 'queue_name' => 'run-queue'],
    );

    expect($branch['queue_connection'])->toBe('branch-connection')
        ->and($branch['queue_name'])->toBe('branch-queue');
});

test('DurableBranchCoordinator falls back to run routing when RoutesDurableBranches returns an empty array', function () {
    config()->set('swarm.durable.parallel.queue.connection', null);
    config()->set('swarm.durable.parallel.queue.name', null);

    $branch = app(DurableBranchCoordinator::class)->withBranchRouting(
        new EmptyRoutedBranchesSwarm,
        RunContext::fromTask('branch-routing-task'),
        ['branch_id' => 'writer_branch', 'agent_class' => FakeWriter::class],
        ['queue_connection' => 'run-connection', 'queue_name' => 'run-queue'],
    );

    expect($branch['queue_connection'])->toBe('run-connection')
        ->and($branch['queue_name'])->toBe('run-queue');
});
