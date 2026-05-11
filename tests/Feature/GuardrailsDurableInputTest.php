<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksInputWhenMatches;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\GuardrailContainer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    config()->set('queue.connections.durable-guardrail-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-guardrail-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['r']);
    FakeWriter::fake(['w']);
    FakeEditor::fake(['e']);

    foreach ([
        ContextStore::class,
        ArtifactRepository::class,
        RunHistoryStore::class,
        DurableRunStore::class,
        SwarmRunner::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }
    GuardrailContainer::refresh(app());
});

test('dispatch durable throws when input guardrail fails before any durable persistence', function () {
    config()->set('swarm.guardrails.input', [BlocksInputWhenMatches::class]);
    $this->app->bind(BlocksInputWhenMatches::class, fn () => new BlocksInputWhenMatches('block-durable-input'));
    GuardrailContainer::refresh($this->app);
    foreach ([ContextStore::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $runId = 'guardrail-durable-preflight-'.uniqid('', true);

    expect(fn () => FakeSequentialSwarm::make()->dispatchDurable(RunContext::from('block-durable-input', $runId)))
        ->toThrow(GuardrailViolation::class);

    expect(DB::table('swarm_durable_runs')->count())->toBe(0);

    $record = app(RunHistoryStore::class)->find($runId);
    expect($record)->not->toBeNull()
        ->and($record['status'])->toBe('failed')
        ->and(($record['error']['class'] ?? null))->toBe(GuardrailViolation::class);
});
