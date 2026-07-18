<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Facades\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\PendingAgentRun;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksInputWhenMatches;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;

it('returns a fluent PendingAgentRun from Swarm::agent()', function () {
    expect(Swarm::agent(new FakeResearcher))->toBeInstanceOf(PendingAgentRun::class);
});

it('runs a single agent through the full governed pipeline and emits the identical audit chain', function () {
    FakeResearcher::fake(['single-agent-output']);

    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);

    $response = Swarm::agent(new FakeResearcher)->prompt('do the thing');

    // The lone agent actually ran and produced output through one step.
    expect($response)->toBeInstanceOf(SwarmResponse::class)
        ->and((string) $response)->toContain('single-agent-output')
        ->and($response->steps)->toHaveCount(1);

    // The SAME evidence chain a multi-agent run emits fired here.
    expect($sink->hasCategory('run.started'))->toBeTrue()
        ->and($sink->hasCategory('step.started'))->toBeTrue()
        ->and($sink->hasCategory('step.completed'))->toBeTrue()
        ->and($sink->hasCategory('run.completed'))->toBeTrue();
});

it('applies per-call guardrails passed to ->guardrails()', function () {
    FakeResearcher::fake(['never-reached']);

    expect(fn () => Swarm::agent(new FakeResearcher)
        ->guardrails([new BlocksInputWhenMatches('blocked-token')])
        ->prompt('blocked-token'))
        ->toThrow(GuardrailViolation::class);
});

it('governed by default: honors globally configured guardrails with no per-call override', function () {
    config()->set('swarm.guardrails.input', [BlocksInputWhenMatches::class]);
    app()->bind(BlocksInputWhenMatches::class, fn () => new BlocksInputWhenMatches('global-block'));
    FakeResearcher::fake(['never-reached']);

    expect(fn () => Swarm::agent(new FakeResearcher)->prompt('global-block'))
        ->toThrow(GuardrailViolation::class);
});

it('exposes streaming as an execution mode on the single-agent front door', function () {
    FakeResearcher::fake(['streamed-output']);

    $response = Swarm::agent(new FakeResearcher)->stream('go');

    expect($response)->toBeInstanceOf(StreamableSwarmResponse::class);
});
