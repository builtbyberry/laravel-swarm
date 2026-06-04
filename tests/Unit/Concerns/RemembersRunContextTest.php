<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;

/**
 * The RemembersRunContext trait implements Conversational::messages() against
 * the active run, and is a graceful no-op outside a swarm.
 */
afterEach(function () {
    ActiveRunContext::exit();
});

/**
 * @return array<int, Message>
 */
function traitMessages(object $agent): array
{
    $messages = $agent->messages();

    return is_array($messages) ? $messages : iterator_to_array($messages);
}

test('the trait satisfies the Conversational contract', function () {
    expect(new RememberingWriter)->toBeInstanceOf(Conversational::class);
});

test('messages() is empty outside a swarm run', function () {
    expect(traitMessages(new RememberingWriter))->toBe([]);
});

test('messages() renders the active run memory', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'trait-run', 'brief', 'remember me');
    ActiveRunContext::enter('trait-run', FakeSequentialSwarm::class, RunContext::fake(['run_id' => 'trait-run']));

    $contents = array_map(fn (Message $m) => $m->content, traitMessages(new RememberingWriter));

    expect($contents)->toContain('brief: remember me');
});

test('the configured role is applied', function () {
    config()->set('swarm.memory.run_context_messages.role', 'user');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'role-run', 'brief', 'hi');
    ActiveRunContext::enter('role-run', FakeSequentialSwarm::class, RunContext::fake(['run_id' => 'role-run']));

    $messages = traitMessages(new RememberingWriter);

    expect($messages[0]->role)->toBe(MessageRole::User);
});

test('runContextMessageRole() can be overridden per agent', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'override-run', 'brief', 'hi');
    ActiveRunContext::enter('override-run', FakeSequentialSwarm::class, RunContext::fake(['run_id' => 'override-run']));

    $agent = new class extends RememberingWriter
    {
        protected function runContextMessageRole(): MessageRole
        {
            return MessageRole::ToolResult;
        }
    };

    $messages = traitMessages($agent);

    expect($messages[0]->role)->toBe(MessageRole::ToolResult);
});

test('mergeRunContextMessages() composes the agent history with the no-op fallback', function () {
    // No active run, so the run-context messages are empty; the hook must still
    // be able to contribute the agent's own history.
    $agent = new class extends RememberingWriter
    {
        protected function mergeRunContextMessages(array $runContextMessages): iterable
        {
            return [new Message(MessageRole::User, 'preamble'), ...$runContextMessages];
        }
    };

    $messages = traitMessages($agent);

    expect($messages)->toHaveCount(1);
    expect($messages[0]->content)->toBe('preamble');
});
