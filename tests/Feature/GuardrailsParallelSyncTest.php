<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\ParallelPolicyProbeStepGuardrail;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\GuardrailContainer;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    config()->set('swarm.guardrails.input', []);
    config()->set('swarm.guardrails.step', []);
    config()->set('swarm.guardrails.output', []);
    config()->set('swarm.guardrails.child_inheritance', 'own_and_global');

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['r']);
    FakeWriter::fake(['w']);
    FakeEditor::fake(['e']);

    GuardrailContainer::refresh($this->app);
});

test('parallel sync existing policy validates each branch before its step row is recorded', function () {
    config()->set('swarm.guardrails.parallel_failure_policy', 'existing');
    config()->set('swarm.guardrails.step', [ParallelPolicyProbeStepGuardrail::class]);
    $this->app->bind(ParallelPolicyProbeStepGuardrail::class, fn ($app) => new ParallelPolicyProbeStepGuardrail(
        $app->make(RunHistoryStore::class),
        true,
    ));
    GuardrailContainer::refresh($this->app);

    $response = FakeParallelSwarm::make()->prompt('parallel-task');

    expect((string) $response)->toContain('r');
});

test('parallel sync batch_validate_before_record validates all branches before any step rows', function () {
    config()->set('swarm.guardrails.parallel_failure_policy', 'batch_validate_before_record');
    config()->set('swarm.guardrails.step', [ParallelPolicyProbeStepGuardrail::class]);
    $this->app->bind(ParallelPolicyProbeStepGuardrail::class, fn ($app) => new ParallelPolicyProbeStepGuardrail(
        $app->make(RunHistoryStore::class),
        false,
    ));
    GuardrailContainer::refresh($this->app);

    $response = FakeParallelSwarm::make()->prompt('parallel-task');

    expect((string) $response)->toContain('r');
});
