<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\DeclinedToolResults;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\DeclinedMemoryAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\DeclinedMemoryParentAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SecondDeclinedMemoryAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DeclinedMemoryDurableStreamingParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DeclinedMemoryGeneratedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DeclinedMemoryNestedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DeclinedMemoryParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DeclinedMemorySequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DeclinedMemoryStaticHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DeclinedMemoryTwoStepSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingMemoryCapturePolicy;
use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

beforeEach(function (): void {
    config()->set('swarm.memory.tools.enabled', true);
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([
        ContextStore::class,
        ArtifactRepository::class,
        RunHistoryStore::class,
        DurableRunStore::class,
        SwarmRunner::class,
        DurableSwarmManager::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

afterEach(function (): void {
    ActiveRunContext::flush();
});

function declinedCall(array $arguments, string $id = 'call-1'): ToolCall
{
    return new ToolCall($id, 'remember', $arguments);
}

function nativeToolStatus(object $step, int $index = 0): string
{
    return $step->nativeResult->toArray()['tools'][$index]['status'];
}

function skipDeclinedMemoryWrites(): void
{
    app()->instance(MemoryCapturePolicy::class, new SkippingMemoryCapturePolicy(['secret']));
    app()->forgetInstance(MemoryStore::class);
    app()->forgetInstance(SwarmMemory::class);
}

dataset('decline causes', [
    'empty key' => [false, ['key' => '', 'value' => 'x'], 'A memory key is required.'],
    'capture-policy skip' => [true, ['key' => 'secret', 'value' => 'x'], 'The entry [secret] was not stored.'],
]);

dataset('declined memory writes', [
    'empty key' => [
        ['key' => '', 'value' => 'x'],
        'A memory key is required.',
    ],
    'reserved key' => [
        ['key' => 'swarm:owned', 'value' => 'x'],
        'Keys starting with [swarm:] are reserved and cannot be written.',
    ],
    'unknown scope' => [
        ['key' => 'k', 'value' => 'v', 'scope' => 'bogus'],
        'Unknown memory scope. Use one of: run, swarm, agent, conversation.',
    ],
    'unaddressable conversation scope' => [
        ['key' => 'k', 'value' => 'v', 'scope' => 'conversation'],
        'The [conversation] scope is not addressable in this run.',
    ],
]);

test('sequential prompt projects every declined memory write as failed', function (array $arguments): void {
    DeclinedMemoryAgent::fake([declinedCall($arguments), 'done']);

    $response = DeclinedMemorySequentialSwarm::make()->prompt('remember');

    expect(nativeToolStatus($response->steps[0]))->toBe('failed');
})->with('declined memory writes');

test('sequential prompt projects a capture-policy skipped write as failed', function (): void {
    skipDeclinedMemoryWrites();
    DeclinedMemoryAgent::fake([declinedCall(['key' => 'secret', 'value' => 'x']), 'done']);

    $response = DeclinedMemorySequentialSwarm::make()->prompt('remember');

    expect(nativeToolStatus($response->steps[0]))->toBe('failed');
});

test('sequential prompt keeps a stored write succeeded and persists it', function (): void {
    DeclinedMemoryAgent::fake([
        declinedCall(['key' => 'topic', 'value' => 'launch plan']),
        'done',
    ]);

    $response = DeclinedMemorySequentialSwarm::make()->prompt(
        RunContext::fake(['run_id' => 'stored-run', 'input' => 'remember']),
    );

    expect(nativeToolStatus($response->steps[0]))->toBe('succeeded')
        ->and(app(SwarmMemory::class)->get(MemoryScope::Run, 'stored-run', 'topic'))->toBe('launch plan');
});

test('sequential stream exposes and replays a declined write as unsuccessful', function (bool $skip, array $arguments, string $message): void {
    if ($skip) {
        skipDeclinedMemoryWrites();
    }
    DeclinedMemoryAgent::fake([declinedCall($arguments), 'done']);

    $stream = DeclinedMemorySequentialSwarm::make()->stream('remember')->storeForReplay();
    $events = collect(iterator_to_array($stream));
    $toolResult = $events->whereInstanceOf(SwarmToolResult::class)->sole();
    $stepEnd = $events->whereInstanceOf(SwarmStepEnd::class)->sole();

    expect($toolResult->successful)->toBeFalse()
        ->and($toolResult->error)->toBe($message)
        ->and($toolResult->toArray()['successful'])->toBeFalse()
        ->and(nativeToolStatus($stepEnd))->toBe('failed');

    $replayed = collect(iterator_to_array(app(SwarmHistory::class)->replay($stream->runId)))
        ->whereInstanceOf(SwarmToolResult::class)
        ->sole();

    expect($replayed->successful)->toBeFalse()
        ->and($replayed->error)->toBe($message);
})->with('decline causes');

test('parallel prompt projects a declined memory write as failed', function (bool $skip, array $arguments, string $message): void {
    if ($skip) {
        skipDeclinedMemoryWrites();
    }
    DeclinedMemoryAgent::fake([declinedCall($arguments), 'done']);

    $response = DeclinedMemoryParallelSwarm::make()->prompt('remember');

    expect(nativeToolStatus($response->steps[0]))->toBe('failed');
})->with('decline causes');

test('durable parallel prompt persists a declined branch write as a failed native tool result', function (bool $skip, array $arguments, string $message): void {
    if ($skip) {
        skipDeclinedMemoryWrites();
    }
    DeclinedMemoryAgent::fake([declinedCall($arguments), 'done']);

    $runId = DeclinedMemoryParallelSwarm::make()->dispatchDurable('remember')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableBranch($runId, 'parallel:0'))->handle($manager);

    $branch = app(DurableRunStore::class)->findBranch($runId, 'parallel:0');

    expect($branch['status'])->toBe('completed')
        ->and($branch['native_result']['tools'][0]['status'])->toBe('failed');
})->with('decline causes');

test('durable parallel branch streaming persists a declined write as unsuccessful', function (bool $skip, array $arguments, string $message): void {
    if ($skip) {
        skipDeclinedMemoryWrites();
    }
    DeclinedMemoryAgent::fake([declinedCall($arguments), 'done']);

    $runId = DeclinedMemoryDurableStreamingParallelSwarm::make()->dispatchDurable('remember')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableBranch($runId, 'parallel:0'))->handle($manager);

    $toolResult = collect(CausalLogView::forRun(app(StreamEventStore::class), $runId)->fold())
        ->whereInstanceOf(SwarmToolResult::class)
        ->sole();
    $branch = app(DurableRunStore::class)->findBranch($runId, 'parallel:0');

    expect($toolResult->successful)->toBeFalse()
        ->and($toolResult->error)->toBe($message)
        ->and($branch['native_result']['tools'][0]['status'])->toBe('failed');
})->with('decline causes');

test('hierarchical streams project a worker declined write as failed', function (string $swarmClass, bool $generated, bool $skip, array $arguments, string $message): void {
    if ($skip) {
        skipDeclinedMemoryWrites();
    }

    if ($generated) {
        FakeHierarchicalCoordinator::fake([[
            'start_at' => 'memory',
            'nodes' => [
                'memory' => [
                    'type' => 'worker',
                    'agent' => DeclinedMemoryAgent::class,
                    'prompt' => 'remember',
                    'next' => 'finish',
                ],
                'finish' => ['type' => 'finish', 'output_from' => 'memory'],
            ],
        ]]);
    }

    DeclinedMemoryAgent::fake([declinedCall($arguments), 'done']);

    $events = collect(iterator_to_array($swarmClass::make()->stream('remember')));
    $toolResult = $events->whereInstanceOf(SwarmToolResult::class)->sole();
    $stepEnd = $events->whereInstanceOf(SwarmStepEnd::class)
        ->first(fn (SwarmStepEnd $event): bool => $event->agentClass === DeclinedMemoryAgent::class);

    expect($toolResult->successful)->toBeFalse()
        ->and(nativeToolStatus($stepEnd))->toBe('failed');
})->with([
    'static hierarchical' => [DeclinedMemoryStaticHierarchicalSwarm::class, false],
    'generated hierarchical' => [DeclinedMemoryGeneratedHierarchicalSwarm::class, true],
])->with('decline causes');

test('queued execution projects a capture-policy skipped write as failed', function (): void {
    skipDeclinedMemoryWrites();
    DeclinedMemoryAgent::fake([declinedCall(['key' => 'secret', 'value' => 'x']), 'done']);
    $context = RunContext::fake(['run_id' => 'queued-declined-memory', 'input' => 'remember']);

    (new InvokeSwarm(DeclinedMemorySequentialSwarm::class, $context->toQueuePayload()))
        ->handle(app(SwarmRunner::class));

    $history = app(RunHistoryStore::class)->find($context->runId);

    expect($history['steps'][0]['native_result']['tools'][0]['status'])->toBe('failed');
});

test('matching uses the declined result text when one invocation reuses a tool call id', function (): void {
    DeclinedMemoryAgent::fake([
        declinedCall(['key' => '', 'value' => 'x']),
        declinedCall(['key' => 'topic', 'value' => 'stored']),
        'done',
    ]);

    $response = DeclinedMemorySequentialSwarm::make()->prompt('remember twice');
    $tools = $response->steps[0]->nativeResult->toArray()['tools'];

    expect(array_column($tools, 'status'))->toBe(['failed', 'succeeded']);
});

test('a stored write that reuses a later declined call id stays succeeded', function (): void {
    // Stored first, declined second: only matching on the declined result text
    // keeps the marker off the stored write that shares its id.
    DeclinedMemoryAgent::fake([
        declinedCall(['key' => 'topic', 'value' => 'stored']),
        declinedCall(['key' => '', 'value' => 'x']),
        'done',
    ]);

    $response = DeclinedMemorySequentialSwarm::make()->prompt('remember twice');
    $tools = $response->steps[0]->nativeResult->toArray()['tools'];

    expect(array_column($tools, 'status'))->toBe(['succeeded', 'failed']);
});

test('declined markers do not cross sequential agent invocations with the same tool call id', function (): void {
    DeclinedMemoryAgent::fake([declinedCall(['key' => '', 'value' => 'x']), 'first done']);
    SecondDeclinedMemoryAgent::fake([
        declinedCall(['key' => 'topic', 'value' => 'stored']),
        'second done',
    ]);

    $response = DeclinedMemoryTwoStepSwarm::make()->prompt('remember twice');

    expect(nativeToolStatus($response->steps[0]))->toBe('failed')
        ->and(nativeToolStatus($response->steps[1]))->toBe('succeeded');
});

test('a nested child decline cannot fail the parent agent-tool result with the same id and text', function (): void {
    $message = 'A memory key is required.';
    DeclinedMemoryAgent::fake([declinedCall(['key' => '', 'value' => 'x']), $message]);
    DeclinedMemoryParentAgent::fake([
        new ToolCall('call-1', 'DeclinedMemoryAgent', ['task' => 'remember']),
        'done',
    ]);

    $response = DeclinedMemoryNestedSwarm::make()->prompt('delegate');
    $tool = $response->steps[0]->nativeResult->toArray()['tools'][0];

    expect($tool['call_id'])->toBe('call-1')
        ->and($tool['status'])->toBe('succeeded');
});

test('the applicator leaves denied and already failed stream results alone', function (bool $denied, bool $failed): void {
    ActiveRunContext::enter(
        'run-1',
        DeclinedMemorySequentialSwarm::class,
        RunContext::fake(['run_id' => 'run-1', 'input' => 'go']),
    );
    ActiveRunContext::declineToolCall('inv-1', 'call-1', 'declined');

    $result = new ToolResultData('call-1', 'remember', [], 'declined', denied: $denied, failed: $failed);
    $event = (new ToolResultEvent('event-1', $result, ! $denied && ! $failed, 'original', 1, denied: $denied))
        ->withInvocationId('inv-1');

    DeclinedToolResults::applyToStreamEvent($event);

    expect($result->failed)->toBe($failed)
        ->and($event->successful)->toBe(! $denied && ! $failed)
        ->and($event->error)->toBe('original')
        ->and(ActiveRunContext::consumeDeclinedToolCall('inv-1', 'call-1', 'declined'))->toBeTrue();
})->with([
    'denied' => [true, false],
    'already failed' => [false, true],
]);
