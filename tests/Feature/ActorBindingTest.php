<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\DefaultActorResolver;
use BuiltByBerry\LaravelSwarm\Contracts\ActorResolver;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Exceptions\MissingActorException;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Context;

function bindActorRecordingSink(): RecordingSwarmAuditSink
{
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);

    return $sink;
}

function fakeAllAgents(): void
{
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
}

beforeEach(function (): void {
    Context::flush();
    config(['swarm.audit.actor.required' => false]);
    config(['swarm.audit.metadata_allowlist' => []]);
});

test('explicit withActor binding flows to audit records', function (): void {
    fakeAllAgents();
    $sink = bindActorRecordingSink();

    $context = RunContext::fromTask('task')->withActor(Actor::system('cron:nightly'));
    FakeSequentialSwarm::make()->run($context);

    $started = $sink->recordsForCategory('run.started')[0];
    expect($started['metadata'])->toHaveKey('actor');
    expect($started['metadata']['actor']['id'])->toBe('cron:nightly');
    expect($started['metadata']['actor']['type'])->toBe('system');
});

test('actor metadata flows even when allowlist is empty', function (): void {
    fakeAllAgents();
    config(['swarm.audit.metadata_allowlist' => []]);
    $sink = bindActorRecordingSink();

    $context = RunContext::fromTask('task')->withActor(Actor::system('billing'));
    FakeSequentialSwarm::make()->run($context);

    $started = $sink->recordsForCategory('run.started')[0];
    expect($started['metadata'])->toHaveKey('actor');
});

test('Context::add binding resolves at run entry', function (): void {
    fakeAllAgents();
    Context::add('swarm:actor', Actor::system('api:dashboard'));
    $sink = bindActorRecordingSink();

    FakeSequentialSwarm::make()->run('task');

    $started = $sink->recordsForCategory('run.started')[0];
    expect($started['metadata']['actor']['id'])->toBe('api:dashboard');
});

test('withActor takes precedence over the bound ActorResolver', function (): void {
    fakeAllAgents();
    Context::add('swarm:actor', Actor::system('would-lose'));
    $sink = bindActorRecordingSink();

    $context = RunContext::fromTask('task')->withActor(Actor::system('explicit-wins'));
    FakeSequentialSwarm::make()->run($context);

    $started = $sink->recordsForCategory('run.started')[0];
    expect($started['metadata']['actor']['id'])->toBe('explicit-wins');
});

test('run entry throws MissingActorException when actor is required and unresolved', function (): void {
    fakeAllAgents();
    config(['swarm.audit.actor.required' => true]);
    app()->instance(ActorResolver::class, new DefaultActorResolver(null));

    expect(fn () => FakeSequentialSwarm::make()->run('task'))
        ->toThrow(MissingActorException::class);
});

test('run entry does not throw when actor is bound explicitly and required is true', function (): void {
    fakeAllAgents();
    config(['swarm.audit.actor.required' => true]);
    app()->instance(ActorResolver::class, new DefaultActorResolver(null));

    $context = RunContext::fromTask('task')->withActor(Actor::system('cron:nightly'));

    expect(fn () => FakeSequentialSwarm::make()->run($context))
        ->not->toThrow(MissingActorException::class);
});

test('null actor with required=true throws', function (): void {
    fakeAllAgents();
    config(['swarm.audit.actor.required' => true]);
    app()->instance(ActorResolver::class, new DefaultActorResolver(null));

    $context = RunContext::fromTask('task');
    $context->withActor(null);

    expect(fn () => FakeSequentialSwarm::make()->run($context))
        ->toThrow(MissingActorException::class);
});

test('audit records omit actor key when no actor is bound', function (): void {
    fakeAllAgents();
    app()->instance(ActorResolver::class, new DefaultActorResolver(null));
    $sink = bindActorRecordingSink();

    FakeSequentialSwarm::make()->run('task');

    $started = $sink->recordsForCategory('run.started')[0];
    expect($started['metadata'])->not->toHaveKey('actor');
});
