<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\FailingAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\MixedFailureSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\PendingAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\StaticSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\ThrowingApprovalAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;

it('preserves the unsupported outcome type across real process workers', function (string $path) {
    // Every worker resolves a declared class in its own application; no parent fake state.
    config()->set('tests.native.plan', ['start_at' => 'parallel', 'nodes' => [
        'parallel' => ['type' => 'parallel', 'branches' => ['pending', 'second'], 'next' => 'finish'],
        'pending' => ['type' => 'worker', 'agent' => PendingAgent::class, 'prompt' => 'task'],
        'finish' => ['type' => 'finish', 'output_from' => 'second'],
        'second' => ['type' => 'worker', 'agent' => PendingAgent::class, 'prompt' => 'second'],
    ]]);
    expect(function () use ($path) {
        if ($path === 'parallel') {
            app(SwarmRunner::class)->parallel([new PendingAgent, new PlainStreamEditor])->prompt('task');
        } elseif ($path === 'static-prompt') {
            StaticSwarm::make()->prompt('task');
        } else {
            iterator_to_array(StaticSwarm::make()->stream('task'));
        }
    })->toThrow(UnsupportedNativeApprovalException::class);
})->with(['parallel', 'static-prompt', 'static-stream']);

it('prioritizes native rejection over an earlier ordinary process failure', function (string $path, string $agent, string $ordinaryPrompt, bool $nativeFirst) {
    config()->set('tests.native.mixed', true);
    config()->set('tests.native.plan', ['start_at' => 'parallel', 'nodes' => [
        'parallel' => ['type' => 'parallel', 'branches' => $nativeFirst ? ['pending', 'ordinary'] : ['ordinary', 'pending'], 'next' => 'finish'],
        'ordinary' => ['type' => 'worker', 'agent' => FailingAgent::class, 'prompt' => $ordinaryPrompt],
        'pending' => ['type' => 'worker', 'agent' => $agent, 'prompt' => 'task'],
        'finish' => ['type' => 'finish', 'output_from' => 'pending'],
    ]]);
    expect(function () use ($path, $agent, $ordinaryPrompt, $nativeFirst) {
        if ($path === 'parallel') {
            app(SwarmRunner::class)->parallel($nativeFirst ? [new $agent, new FailingAgent] : [new FailingAgent, new $agent])->prompt($ordinaryPrompt);
        } elseif ($path === 'static-prompt') {
            StaticSwarm::make()->prompt('task');
        } else {
            iterator_to_array(StaticSwarm::make()->stream('task'));
        }
    })->toThrow($agent === PendingAgent::class ? UnsupportedNativeApprovalException::class : ApprovalNotResumableException::class);
})->with(['parallel', 'static-prompt', 'static-stream'])->with([PendingAgent::class, ThrowingApprovalAgent::class])->with(['task', 'closure-failure', 'resource-failure'])->with([false, true]);

it('keeps ordinary process failure ordering when no approval is pending', function () {
    expect(fn () => app(SwarmRunner::class)->parallel([new FailingAgent, new PlainStreamEditor])->prompt('task'))
        ->toThrow(RuntimeException::class, 'ordinary-first');
});

it('permanently fails a cache-backed queued mixed batch under a real process driver', function (bool $nativeThrows) {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.queue.tries', 5);
    config()->set('tests.native.throw', $nativeThrows);
    config()->set('queue.connections.native-worker', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'test', 'retry_after' => 90]);
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    $context = RunContext::fromTask('task');
    $queue = app('queue')->connection('native-worker');
    $queue->push(new InvokeSwarm(MixedFailureSwarm::class, $context->toQueuePayload()));
    $job = $queue->pop('test');
    expect($job->maxTries())->toBe(5);
    expect(fn () => app('queue.worker')->process('native-worker', $job, new WorkerOptions(maxTries: 5)))
        ->toThrow($nativeThrows ? ApprovalNotResumableException::class : UnsupportedNativeApprovalException::class);
    expect($job->hasFailed())->toBeTrue()->and($job->isReleased())->toBeFalse()->and($queue->size('test'))->toBe(0);
})->with([false, true]);
