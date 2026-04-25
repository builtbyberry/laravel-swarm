<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\UnresolvableParallelAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Jobs\NoOpQueuedJob;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support\ResolvedSwarmOutput;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support\UnboundQueuedDependency;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ConfigDrivenSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ContainerResolvedQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DependencyInjectedQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\EmptyRunnableSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalDuplicateWorkerSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\UnresolvableParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\UnresolvableQueuedSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function preventQueuedSwarmRedispatch(object $response): void
{
    $dispatchableProperty = new ReflectionProperty($response, 'dispatchable');
    $dispatchableProperty->setAccessible(true);

    $dispatchable = $dispatchableProperty->getValue($response);

    $jobProperty = new ReflectionProperty($dispatchable, 'job');
    $jobProperty->setAccessible(true);
    $jobProperty->setValue($dispatchable, new NoOpQueuedJob);
}

beforeEach(function () {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('queue fails before dispatching empty swarms', function () {
    expect(fn () => EmptyRunnableSwarm::make()->queue('queued-task'))
        ->toThrow(SwarmException::class, 'EmptyRunnableSwarm: swarm has no agents. Add at least one agent to agents().');
});

test('queue fails before dispatching invalid topology config', function () {
    config()->set('swarm.topology', 'pipeline');

    expect(fn () => ConfigDrivenSequentialSwarm::make()->queue('queued-task'))
        ->toThrow(SwarmException::class, 'Invalid swarm topology [pipeline]. Supported topologies: sequential, parallel, hierarchical.');
});

test('queue fails before dispatching invalid timeout config', function () {
    config()->set('swarm.timeout', 0);

    expect(fn () => FakeSequentialSwarm::make()->queue('queued-task'))
        ->toThrow(SwarmException::class, 'Swarm timeout must be a positive integer.');
});

test('queue fails before dispatching invalid max step config', function () {
    config()->set('swarm.max_agent_steps', 0);

    expect(fn () => FakeSequentialSwarm::make()->queue('queued-task'))
        ->toThrow(SwarmException::class, 'Swarm max agent steps must be a positive integer.');
});

test('queue fails before dispatching non container resolvable parallel agents', function () {
    expect(fn () => UnresolvableParallelSwarm::make()->queue('queued-task'))
        ->toThrow(SwarmException::class, UnresolvableParallelSwarm::class.': parallel agent ['.UnresolvableParallelAgent::class.'] must be container-resolvable because Laravel Concurrency serializes worker callbacks.');
});

test('queue fails before dispatching duplicate hierarchical worker classes', function () {
    expect(fn () => FakeHierarchicalDuplicateWorkerSwarm::make()->queue('queued-task'))
        ->toThrow(SwarmException::class, FakeHierarchicalDuplicateWorkerSwarm::class.': agents() contains duplicate agent class '.FakeWriter::class.'. Hierarchical worker classes must be unique.');
});

test('queue fails when active runtime context persistence is disabled', function () {
    config()->set('swarm.capture.active_context', false);

    expect(fn () => FakeSequentialSwarm::make()->queue('queued-task'))
        ->toThrow(SwarmException::class, 'Queued and durable swarms require active runtime context persistence so workers can continue or recover the run.');
});

test('queue rejects explicit run contexts that exceed configured input payload limits', function () {
    config()->set('swarm.limits.max_input_bytes', 80);

    $context = RunContext::from([
        'input' => 'tiny',
        'data' => ['large' => str_repeat('x', 120)],
    ], 'oversized-queued-context-run-id');

    expect(fn () => FakeSequentialSwarm::make()->queue($context))
        ->toThrow(SwarmException::class, 'Swarm input payload is');
});

test('queue rejects oversized explicit run contexts even when overflow truncation is enabled', function () {
    config()->set('swarm.limits.max_input_bytes', 80);
    config()->set('swarm.limits.overflow', 'truncate');

    $context = RunContext::from([
        'input' => 'tiny',
        'data' => ['large' => str_repeat('x', 120)],
    ], 'oversized-truncated-queued-context-run-id');

    expect(fn () => FakeSequentialSwarm::make()->queue($context))
        ->toThrow(SwarmException::class, 'Swarm input payload is');
});

test('queued swarm jobs can execute with a preserved run context', function () {
    $context = RunContext::from([
        'input' => 'queued-task',
        'data' => ['draft_id' => 42],
        'metadata' => ['tenant_id' => 'acme'],
    ], 'queued-run-id');
    $job = new InvokeSwarm(FakeSequentialSwarm::class, $context->toQueuePayload());

    $job->handle(app(SwarmRunner::class));

    FakeResearcher::assertPrompted('queued-task');
    FakeWriter::assertPrompted('research-out');
    FakeEditor::assertPrompted('writer-out');
    expect($job->task['data'])
        ->toHaveKey('draft_id', 42)
        ->and($job->task['metadata'])
        ->toHaveKey('tenant_id', 'acme');
});

test('queued swarm retries do not re-run completed database-backed executions', function () {
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    $context = RunContext::from('queued-task', 'idempotent-run-id');
    $job = new InvokeSwarm(FakeSequentialSwarm::class, $context->toQueuePayload());

    $job->handle(app(SwarmRunner::class));
    $stepsBeforeRetry = DB::table('swarm_run_histories')->where('run_id', 'idempotent-run-id')->value('steps');

    FakeResearcher::assertPrompted('queued-task');
    FakeWriter::assertPrompted('research-out');
    FakeEditor::assertPrompted('writer-out');

    FakeResearcher::fake(['retry-research']);
    FakeWriter::fake(['retry-writer']);
    FakeEditor::fake(['retry-editor']);

    $job->handle(app(SwarmRunner::class));

    expect(DB::table('swarm_run_histories')->where('run_id', 'idempotent-run-id')->value('status'))->toBe('completed');
    expect(DB::table('swarm_run_histories')->where('run_id', 'idempotent-run-id')->value('steps'))->toBe($stepsBeforeRetry);

    FakeResearcher::assertPrompted('queued-task');
    FakeWriter::assertPrompted('research-out');
    FakeEditor::assertPrompted('writer-out');
});

test('queued swarm duplicate completion does not replay deprecated then callbacks', function () {
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    $state = (object) ['callbacks' => 0];
    $context = RunContext::from('queued-task', 'duplicate-callback-run-id');

    $job = (new InvokeSwarm(FakeSequentialSwarm::class, $context->toQueuePayload()))
        ->then(function (SwarmResponse $response) use ($state): void {
            expect($response->output)->toBe('editor-out');
            $state->callbacks++;
        });

    $job->handle(app(SwarmRunner::class));
    $job->handle(app(SwarmRunner::class));

    expect($state->callbacks)->toBe(1);
});

test('queued swarm retries do not restart failed database-backed executions', function () {
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    $context = RunContext::from('queued-task', 'failed-run-id');
    $job = new InvokeSwarm(FailingQueuedSwarm::class, $context->toQueuePayload());

    try {
        $job->handle(app(SwarmRunner::class));
        $this->fail('Expected the queued swarm to throw.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Queued swarm failed.');
    }

    $updatedAt = DB::table('swarm_run_histories')->where('run_id', 'failed-run-id')->value('updated_at');

    $job->handle(app(SwarmRunner::class));

    expect(DB::table('swarm_run_histories')->where('run_id', 'failed-run-id')->value('status'))->toBe('failed');
    expect(DB::table('swarm_run_histories')->where('run_id', 'failed-run-id')->value('updated_at'))->toBe($updatedAt);
});

test('queued database failures redact persisted messages when capture is disabled', function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    $context = RunContext::from('queued-task', 'redacted-failed-run-id');
    $job = new InvokeSwarm(FailingQueuedSwarm::class, $context->toQueuePayload());

    expect(fn () => $job->handle(app(SwarmRunner::class)))
        ->toThrow(RuntimeException::class, 'Queued swarm failed.');

    $error = json_decode((string) DB::table('swarm_run_histories')->where('run_id', 'redacted-failed-run-id')->value('error'), true);

    expect($error)->toMatchArray([
        'class' => RuntimeException::class,
        'message' => '[redacted]',
    ]);
});

test('queued swarm retries do not run while another worker still holds the lease', function () {
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    $context = RunContext::from('queued-task', 'leased-run-id');

    DB::table('swarm_run_histories')->insert([
        'run_id' => 'leased-run-id',
        'swarm_class' => FakeSequentialSwarm::class,
        'topology' => 'sequential',
        'status' => 'running',
        'context' => json_encode($context->toArray()),
        'metadata' => json_encode(['swarm_class' => FakeSequentialSwarm::class, 'topology' => 'sequential']),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => null,
        'expires_at' => now()->addHour(),
        'execution_token' => 'active-token',
        'leased_until' => now()->addMinutes(5),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new InvokeSwarm(FakeSequentialSwarm::class, $context->toQueuePayload());
    $job->handle(app(SwarmRunner::class));

    FakeResearcher::assertNeverPrompted();
    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
});

test('queued swarm retries can reclaim expired leases', function () {
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    $context = RunContext::from('queued-task', 'expired-lease-run-id');

    DB::table('swarm_run_histories')->insert([
        'run_id' => 'expired-lease-run-id',
        'swarm_class' => FakeSequentialSwarm::class,
        'topology' => 'sequential',
        'status' => 'running',
        'context' => json_encode($context->toArray()),
        'metadata' => json_encode(['swarm_class' => FakeSequentialSwarm::class, 'topology' => 'sequential']),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => null,
        'expires_at' => now()->addHour(),
        'execution_token' => 'expired-token',
        'leased_until' => now()->subMinute(),
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $job = new InvokeSwarm(FakeSequentialSwarm::class, $context->toQueuePayload());
    $job->handle(app(SwarmRunner::class));

    expect(DB::table('swarm_run_histories')->where('run_id', 'expired-lease-run-id')->value('status'))->toBe('completed');
    expect(DB::table('swarm_run_histories')->where('run_id', 'expired-lease-run-id')->value('execution_token'))->toBeNull();

    FakeResearcher::assertPrompted('queued-task');
    FakeWriter::assertPrompted('research-out');
    FakeEditor::assertPrompted('writer-out');
});

test('queued swarm does not dispatch failed events after losing the lease', function () {
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Event::fake();

    $context = RunContext::from('queued-task', 'lease-loss-run-id');

    DB::table('swarm_run_histories')->insert([
        'run_id' => 'lease-loss-run-id',
        'swarm_class' => FailingQueuedSwarm::class,
        'topology' => 'sequential',
        'status' => 'running',
        'context' => json_encode($context->toArray()),
        'metadata' => json_encode(['swarm_class' => FailingQueuedSwarm::class, 'topology' => 'sequential']),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => null,
        'expires_at' => now()->addHour(),
        'execution_token' => 'active-token',
        'leased_until' => now()->subMinute(),
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $job = new InvokeSwarm(FailingQueuedSwarm::class, $context->toQueuePayload());

    try {
        $job->handle(app(SwarmRunner::class));
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Queued swarm failed.');
    }

    DB::table('swarm_run_histories')
        ->where('run_id', 'lease-loss-run-id')
        ->update([
            'execution_token' => 'replacement-token',
            'leased_until' => now()->addMinutes(5),
            'updated_at' => now(),
        ]);

    Event::fake();

    $job = new InvokeSwarm(FailingQueuedSwarm::class, $context->toQueuePayload());
    $job->handle(app(SwarmRunner::class));

    Event::assertNotDispatched(SwarmFailed::class, fn ($event) => $event->runId === 'lease-loss-run-id');
});

test('queued swarms serialize structured task arrays into queue-safe payloads', function () {
    $queued = FakeSequentialSwarm::make()->queue([
        'draft_id' => 42,
        'tenant_id' => 'acme',
    ]);

    $job = $queued->getJob();

    expect($job->task)
        ->toHaveKey('run_id')
        ->toHaveKey('input', '{"draft_id":42,"tenant_id":"acme"}')
        ->and($job->task['data'])
        ->toHaveKey('draft_id', 42)
        ->toHaveKey('tenant_id', 'acme');
    expect($job->task['metadata'])->toBe([]);
});

test('queued structured task input survives worker reconstruction', function () {
    $queued = FakeSequentialSwarm::make()->queue([
        'draft_id' => 42,
        'tenant_id' => 'acme',
    ]);

    $job = $queued->getJob();
    preventQueuedSwarmRedispatch($queued);
    $job->handle(app(SwarmRunner::class));

    FakeResearcher::assertPrompted('{"draft_id":42,"tenant_id":"acme"}');
    FakeWriter::assertPrompted('research-out');
    FakeEditor::assertPrompted('writer-out');

    $history = app(SwarmHistory::class)->find($queued->runId);

    expect($history['context']['data'])
        ->toHaveKey('draft_id', 42)
        ->toHaveKey('tenant_id', 'acme')
        ->toHaveKey('last_output', 'editor-out')
        ->toHaveKey('steps', 3);
    expect($history['context']['metadata'])
        ->toHaveKey('swarm_class', FakeSequentialSwarm::class)
        ->toHaveKey('topology', 'sequential');
});

test('queued swarms accept explicit run contexts', function () {
    $queued = FakeSequentialSwarm::make()->queue(RunContext::from([
        'input' => 'queued-task',
        'data' => ['draft_id' => 42],
        'metadata' => ['tenant_id' => 'acme'],
    ], 'queued-run-id'));

    $job = $queued->getJob();

    expect($job->task)
        ->toHaveKey('run_id', 'queued-run-id')
        ->toHaveKey('input', 'queued-task')
        ->and($job->task['data'])
        ->toHaveKey('draft_id', 42);
});

test('queued swarm reconstruction fails fast for malformed serialized payloads', function () {
    $job = new InvokeSwarm(FakeSequentialSwarm::class, [
        'run_id' => 'queued-run-id',
        'input' => 'queued-task',
        'data' => 'bad',
        'metadata' => ['tenant_id' => 'acme'],
        'artifacts' => [],
    ]);

    expect(fn () => $job->handle(app(SwarmRunner::class)))
        ->toThrow(SwarmException::class, 'RunContext::fromPayload() expects [data] to be an array.');
});

test('queued swarm dispatches started events with queue execution mode', function () {
    Event::fake();

    $queued = FakeSequentialSwarm::make()->queue('queued-task');
    $job = $queued->getJob();
    preventQueuedSwarmRedispatch($queued);
    $job->handle(app(SwarmRunner::class));

    Event::assertDispatched(SwarmStarted::class, fn (SwarmStarted $event) => $event->input === 'queued-task'
        && $event->executionMode === 'queue');
});

test('queued swarm completion callbacks run through the pending dispatch path', function () {
    $state = (object) ['response' => null];

    $queued = FakeSequentialSwarm::make()
        ->queue('queued-task')
        ->then(function (SwarmResponse $response) use ($state): void {
            $state->response = $response;
        });

    $job = $queued->getJob();
    preventQueuedSwarmRedispatch($queued);
    $job->handle(app(SwarmRunner::class));

    expect($state->response)->toBeInstanceOf(SwarmResponse::class);
    expect($state->response?->output)->toBe('editor-out');
});

test('queued swarm response preserves fluency after queue configuration methods', function () {
    $state = (object) ['response' => null];

    $queued = FakeSequentialSwarm::make()
        ->queue('queued-task')
        ->onQueue('swarm-testing')
        ->then(function (SwarmResponse $response) use ($state): void {
            $state->response = $response;
        });

    expect($queued)->toBeInstanceOf(QueuedSwarmResponse::class);
    expect($queued->runId)->not->toBeNull();
    expect($queued->getJob()->queue)->toBe('swarm-testing');

    $job = $queued->getJob();
    preventQueuedSwarmRedispatch($queued);
    $job->handle(app(SwarmRunner::class));

    expect($state->response)->toBeInstanceOf(SwarmResponse::class);
    expect($state->response?->output)->toBe('editor-out');
});

test('queued swarm response returns raw values for non dispatch proxy methods', function () {
    $queued = FakeSequentialSwarm::make()->queue('queued-task');

    expect($queued->getJob())->toBeInstanceOf(InvokeSwarm::class);
});

test('queued swarm failure callbacks run through the pending dispatch path', function () {
    $state = (object) ['exception_class' => null, 'exception_message' => null];
    $queued = FailingQueuedSwarm::make()
        ->queue('queued-task')
        ->catch(function (Throwable $exception) use ($state): void {
            $state->exception_class = $exception::class;
            $state->exception_message = $exception->getMessage();
        });
    $job = $queued->getJob();
    preventQueuedSwarmRedispatch($queued);

    try {
        $job->handle(app(SwarmRunner::class));

        $this->fail('Expected the queued swarm to throw.');
    } catch (RuntimeException $exception) {
        $job->failed($exception);
        expect($exception->getMessage())->toBe('Queued swarm failed.');
    }

    expect($state->exception_class)->toBe(RuntimeException::class);
    expect($state->exception_message)->toBe('Queued swarm failed.');
});

test('queued swarms resolve a fresh instance from the container when handled', function () {
    $state = (object) ['response' => null];

    $resolvedOutput = new ResolvedSwarmOutput;
    $resolvedOutput->value = 'resolved-output';

    app()->instance(ResolvedSwarmOutput::class, $resolvedOutput);

    $queued = DependencyInjectedQueuedSwarm::make()
        ->queue('queued-task')
        ->then(function (SwarmResponse $response) use ($state): void {
            $state->response = $response;
        });

    $job = $queued->getJob();
    preventQueuedSwarmRedispatch($queued);
    $job->handle(app(SwarmRunner::class));

    expect($state->response)->toBeInstanceOf(SwarmResponse::class);
    expect($state->response?->output)->toBe('resolved-output');
});

test('queue fails fast for swarms with runtime constructor state', function () {
    expect(fn () => (new ContainerResolvedQueuedSwarm('runtime-output'))->queue('queued-task'))
        ->toThrow(
            NonQueueableSwarmException::class,
            'Queued swarms must be container-resolvable workflow definitions. [BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ContainerResolvedQueuedSwarm] cannot be queued because constructor parameter [$output] uses [string] instead of a container dependency. Do not put per-execution state in the swarm constructor; pass it in the task or RunContext instead.',
        );
});

test('queue fails fast when the swarm cannot be resolved from the container', function () {
    $dependency = new class implements UnboundQueuedDependency {};

    expect(fn () => (new UnresolvableQueuedSwarm($dependency))->queue('queued-task'))
        ->toThrow(
            NonQueueableSwarmException::class,
            'Queued swarms must be container-resolvable workflow definitions. [BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\UnresolvableQueuedSwarm] could not be resolved from the container for queued execution. Underlying container error: Target [BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support\UnboundQueuedDependency] is not instantiable while building [BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\UnresolvableQueuedSwarm].',
        );
});

test('run still works for swarms with runtime constructor state', function () {
    $response = (new ContainerResolvedQueuedSwarm('runtime-output'))->run('queued-task');

    expect($response->output)->toBe('runtime-output');
});
