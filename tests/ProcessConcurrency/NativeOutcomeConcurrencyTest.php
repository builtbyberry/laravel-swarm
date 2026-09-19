<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\PendingAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\StaticSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;

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
