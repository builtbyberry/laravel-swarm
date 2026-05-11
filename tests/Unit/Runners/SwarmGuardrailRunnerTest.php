<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\SwarmGuardrailRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\TaggingInputGuardrail;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence\ConfigurableFindRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\GuardrailMergeChildSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\GuardrailMergeParentSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\GuardrailContainer;

beforeEach(function () {
    TaggingInputGuardrail::resetLog();
    config()->set('swarm.guardrails.child_inheritance', 'own_and_global');
    GuardrailContainer::refresh($this->app);
});

test('input guardrails run global config classes before swarm DefinesGuardrails entries', function () {
    config()->set('swarm.guardrails.input', [TaggingInputGuardrail::class]);
    $this->app->bind(TaggingInputGuardrail::class, fn () => new TaggingInputGuardrail('config'));

    $store = new ConfigurableFindRunHistoryStore;
    $this->app->instance(RunHistoryStore::class, $store);

    app(SwarmGuardrailRunner::class)->validateInput(new GuardrailMergeChildSwarm, RunContext::from('task'));

    expect(TaggingInputGuardrail::$log)->toBe(['config', 'child']);
});

test('own_global_and_parent merges parent DefinesGuardrails after the child swarm', function () {
    config()->set('swarm.guardrails.input', []);
    config()->set('swarm.guardrails.child_inheritance', 'own_global_and_parent');

    $store = new ConfigurableFindRunHistoryStore;
    $store->findResult = [
        'swarm_class' => GuardrailMergeParentSwarm::class,
    ];
    $this->app->instance(RunHistoryStore::class, $store);

    app(SwarmGuardrailRunner::class)->validateInput(new GuardrailMergeChildSwarm, RunContext::from([
        'input' => 'task',
        'metadata' => ['parent_run_id' => 'parent-run-1'],
    ]));

    expect(TaggingInputGuardrail::$log)->toBe(['child', 'parent']);
});
