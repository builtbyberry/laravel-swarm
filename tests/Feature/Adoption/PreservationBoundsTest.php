<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Runners\DispatchValidator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRetryHandler;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableSignalHandler;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeWire;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\WorkflowAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\WorkflowSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\WorkflowTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\AgentPrompted;

covers(DispatchValidator::class, DurableRetryHandler::class, DurableSignalHandler::class, DatabaseDurableRunStore::class);

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('ai.providers.openai.key', 'test-key');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Http::preventStrayRequests();
    WorkflowAgent::$trace = [];
    WorkflowAgent::$generationSteps = [];
    WorkflowTool::$effects = [];
});

it('requires concrete durable stores even when a custom context wrapper satisfies the contract', function () {
    $store = app(ContextStore::class);
    expect($store)->toBeInstanceOf(DatabaseContextStore::class);
    app()->instance(ContextStore::class, new class($store) implements ContextStore
    {
        public function __construct(private ContextStore $inner) {}

        public function put(RunContext $context, int $ttlSeconds): void
        {
            $this->inner->put($context, $ttlSeconds);
        }

        public function find(string $runId): ?array
        {
            return $this->inner->find($runId);
        }
    });
    expect(fn () => WorkflowSwarm::make()->dispatchDurable('task'))
        ->toThrow(SwarmException::class, 'requires database-backed swarm persistence');
    expect(DB::table('swarm_durable_runs')->count())->toBe(0);
    Http::assertNothingSent();
});

it('retains the signal record before release crash gap and run scoped idempotency', function () {
    $store = new class(DB::connection(), config(), app(SwarmPersistenceCipher::class)) extends DatabaseDurableRunStore
    {
        public bool $crash = true;

        public function releaseWaitWithSignal(string $runId, string $name, int $signalId): bool
        {
            if ($this->crash) {
                throw new RuntimeException('crash before wait release');
            }

            return parent::releaseWaitWithSignal($runId, $name, $signalId);
        }
    };
    app()->instance(DurableRunStore::class, $store);
    $response = WorkflowSwarm::make()->dispatchDurable('sealed-task');
    $manager = app(DurableSwarmManager::class);
    $manager->wait($response->runId, 'approval');
    expect(fn () => $response->signal('approval', ['value' => 'signal-secret'], 'same-key'))
        ->toThrow(RuntimeException::class, 'crash before wait release');
    expect($store->signals($response->runId))->toHaveCount(1)
        ->and($manager->find($response->runId)['status'])->toBe('waiting')
        ->and($store->waits($response->runId)[0]['status'])->toBe('waiting');
    $store->crash = false;
    $duplicate = $response->signal('different-name', ['value' => 'changed'], 'same-key');
    expect($duplicate->duplicate)->toBeTrue()->and($duplicate->accepted)->toBeFalse()
        ->and($store->signals($response->runId)[0]['name'])->toBe('approval')
        ->and($store->signals($response->runId)[0]['payload'])->toBe(['value' => 'signal-secret']);
    $same = $response->signal('approval', ['value' => 'signal-secret'], 'same-key');
    expect($same->duplicate)->toBeTrue()->and($same->accepted)->toBeFalse()
        ->and($manager->find($response->runId)['status'])->toBe('waiting');
    expect($response->signal('approval', ['value' => 'signal-secret'], 'new-key')->accepted)->toBeTrue();
    $other = WorkflowSwarm::make()->dispatchDurable('other');
    expect($other->signal('approval', [], 'same-key')->duplicate)->toBeFalse();
});

it('retains captured signal plaintext and redacted operational payloads with sealing enabled', function (bool $capture) {
    config()->set('swarm.capture.inputs', $capture);
    config()->set('swarm.capture.outputs', $capture);
    $response = WorkflowSwarm::make()->dispatchDurable('sealed-task');
    $manager = app(DurableSwarmManager::class);
    $manager->wait($response->runId, 'approval');
    expect($response->signal('approval', ['value' => 'signal-secret'], 'key')->accepted)->toBeTrue();
    $expected = ['value' => $capture ? 'signal-secret' : '[redacted]'];
    $raw = DB::table('swarm_durable_signals')->where('run_id', $response->runId)->value('payload');
    expect(json_decode($raw, true))->toBe($expected)
        ->and(app(ContextStore::class)->find($response->runId)['metadata']['durable_signals']['approval']['payload'])->toBe($expected)
        ->and(DB::table('swarm_contexts')->where('run_id', $response->runId)->value('input'))->toStartWith('sw0:');
})->with([true, false]);

it('does not age reclaim a child intent claimed before child creation', function () {
    $response = WorkflowSwarm::make()->dispatchDurable('parent-task');
    $store = app(DurableRunStore::class);
    $store->createChildRun($response->runId, 'uncreated-child', WorkflowSwarm::class, 'child-wait', ['input' => 'child-task']);
    app(DurableSwarmManager::class)->wait($response->runId, 'child-wait');
    expect($store->markChildRunDispatched('uncreated-child'))->toBeTrue();
    $this->travel(2)->days();
    app(DurableSwarmManager::class)->recover(runId: $response->runId);
    expect($store->undispatchedChildRuns($response->runId))->toBe([])
        ->and($store->find('uncreated-child'))->toBeNull()
        ->and($store->childRunForChild('uncreated-child')['dispatched_at'])->not->toBeNull()
        ->and($store->find($response->runId)['status'])->toBe('waiting');
    Http::assertNothingSent();
});

it('can repeat a native tool effect when workflow retry follows native completion before checkpoint', function () {
    config()->set('tests.adoption.tools', 'direct');
    Event::fake([SwarmStepCompleted::class]);
    $nativeCompletions = 0;
    app('events')->listen(AgentPrompted::class, function ($event) use (&$nativeCompletions) {
        expect($event->response->text)->toBe('native-answer')
            ->and($event->prompt->prompt)->toBe('task');
        if (++$nativeCompletions === 1) {
            throw new RuntimeException('native complete before swarm checkpoint');
        }
    });
    $requests = 0;
    Http::fake(function (Request $request) use (&$requests) {
        $requests++;
        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and($request['input'][1]['content'][0]['text'])->toBe('inner outer task')
            ->and($request['metadata'])->toBe(['preservation' => 'native-options']);

        return Http::response(NativeWire::response(tool: $requests % 2 === 1));
    });
    $response = WorkflowSwarm::make()->dispatchDurable('task');
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    $run = $manager->find($response->runId);
    expect($nativeCompletions)->toBe(1)->and(WorkflowTool::$effects)->toBe(['effect-secret'])
        ->and($run['status'])->toBe('pending')->and($run['retry_attempt'])->toBe(1)
        ->and($run['next_retry_at'])->not->toBeNull()->and($run['next_step_index'])->toBe(0)
        ->and(app(RunHistoryStore::class)->find($response->runId)['steps'])->toBe([]);
    Event::assertNotDispatched(SwarmStepCompleted::class);
    Http::assertSentCount(2);
    $this->travel(61)->seconds();
    $manager->recover(runId: $response->runId);
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    expect($manager->find($response->runId)['status'])->toBe('completed')
        ->and($nativeCompletions)->toBe(2)->and(WorkflowTool::$effects)->toBe(['effect-secret', 'effect-secret']);
    Event::assertDispatchedTimes(SwarmStepCompleted::class, 1);
    Http::assertSentCount(4);
    expect(WorkflowAgent::$trace)->toBe(array_merge(...array_fill(0, 4, ['outer:task', 'inner:outer task'])))
        ->and(array_column(WorkflowAgent::$generationSteps, 'number'))->toBe([0, 1, 0, 1]);
});
