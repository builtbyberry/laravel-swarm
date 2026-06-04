<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RememberingConversationSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RememberingRestrictiveSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\RestrictivePropagationPolicy;
use Laravel\Ai\Messages\Message;

/**
 * The RemembersRunContext trait renders the agent-visible memory view as
 * laravel/ai messages — so what it renders must be exactly what the propagation
 * policy permits, nothing more. These tests pin that coupling: the trait surfaces
 * the ConversationPropagationPolicy transcript but hides arbitrary Run memory by
 * default, reflects the include_run_memory toggle, and under a restrictive policy
 * renders only the allow-listed entry.
 */
pest()->group('compliance');

beforeEach(function () {
    config()->set('swarm.memory.capture_step_output', true);
    RememberingWriter::resetCaptured();
    FakeResearcher::fake(fn (): string => 'research-out');
    RememberingWriter::fake(fn (): string => 'writer-out');
});

afterEach(function () {
    ActiveRunContext::exit();
});

test('the trait renders only ConversationPropagationPolicy-permitted entries', function () {
    // An arbitrary Run-scoped key the transcript-only policy must hide.
    app(SwarmMemory::class)->put(MemoryScope::Run, 'conv-trait-run', 'scratch', 'should-not-render');

    RememberingConversationSwarm::make()->run(RunContext::from('go', 'conv-trait-run'));

    $contents = array_map(
        static fn (Message $message): ?string => $message->content,
        RememberingWriter::$capturedMessages[0],
    );

    expect($contents)->toContain('swarm:step.0.output: research-out')  // permitted: prior transcript turn
        ->not->toContain('scratch: should-not-render');                // hidden: not a step output
});

test('the trait surfaces additional Run memory when include_run_memory is enabled', function () {
    config()->set('swarm.memory.conversation_view.include_run_memory', true);

    app(SwarmMemory::class)->put(MemoryScope::Run, 'conv-trait-run', 'scratch', 'now-rendered');

    RememberingConversationSwarm::make()->run(RunContext::from('go', 'conv-trait-run'));

    $contents = array_map(
        static fn (Message $message): ?string => $message->content,
        RememberingWriter::$capturedMessages[0],
    );

    expect($contents)->toContain('swarm:step.0.output: research-out')
        ->toContain('scratch: now-rendered');
});

test('the trait under a restrictive policy renders only the allow-listed entry', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'conv-restrictive-run', RestrictivePropagationPolicy::ALLOWED_KEY, 'visible');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'conv-restrictive-run', 'secret', 'hidden');

    RememberingRestrictiveSwarm::make()->run(RunContext::from('go', 'conv-restrictive-run'));

    $contents = array_map(
        static fn (Message $message): ?string => $message->content,
        RememberingWriter::$capturedMessages[0],
    );

    expect($contents)->toContain(RestrictivePropagationPolicy::ALLOWED_KEY.': visible')
        ->not->toContain('secret: hidden');
});
