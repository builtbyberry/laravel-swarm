<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RememberingConversationSwarm;
use Laravel\Ai\Messages\Message;

/**
 * The end-to-end goal of #163: a RemembersRunContext agent paired with
 * ConversationPropagationPolicy renders the run's prior step outputs as a real
 * turn-by-turn conversation — which, before per-step capture existed, was empty
 * under the default policy.
 */
beforeEach(function () {
    RememberingWriter::resetCaptured();
    FakeResearcher::fake(['research-out']);
    RememberingWriter::fake(['writer-out']);
});

afterEach(function () {
    ActiveRunContext::exit();
});

test('the writer sees the prior step output as a rendered conversation turn', function () {
    RememberingConversationSwarm::make()->run(RunContext::from('go', 'transcript-run'));

    expect(RememberingWriter::$capturedMessages)->not->toBeEmpty();

    $contents = array_map(
        static fn (Message $message): ?string => $message->content,
        RememberingWriter::$capturedMessages[0],
    );

    // It sees the researcher's step-0 output...
    expect($contents)->toContain('swarm:step.0.output: research-out');
    // ...and not its own (not-yet-produced) step-1 output.
    expect($contents)->not->toContain('swarm:step.1.output: writer-out');

    expect(ActiveRunContext::current())->toBeNull();
});
