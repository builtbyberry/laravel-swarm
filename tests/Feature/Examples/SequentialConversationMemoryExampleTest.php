<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Examples\StarterExampleRenderer;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->namespace = StarterExampleRenderer::render('sequential-conversation-memory');
});

test('sequential-conversation-memory carries a remembered subject from step one to step two through memory', function () {
    $swarmClass = $this->namespace.'\\Ai\\Swarms\\SequentialConversationMemory\\ConversationMemory';

    expect(class_exists($swarmClass))->toBeTrue();

    $response = $swarmClass::make()->prompt('Following up on ticket HD-2291 — the export still times out on large reports.');

    expect($response)->toBeInstanceOf(SwarmResponse::class)
        ->and($response->steps)->toHaveCount(2);

    // Step one remembers the subject but deliberately does NOT echo it, so the
    // only channel that can carry it to step two is Swarm memory.
    expect($response->steps[0]->output)->not->toContain('HD-2291');

    // Sequential contract: step two's input is step one's (subject-free) output.
    expect($response->steps[1]->input)->toBe($response->steps[0]->output);

    // Step two recalled the subject from memory and it shapes the final reply —
    // proving the value written in step one flowed through memory, not the prompt.
    expect((string) $response)->toContain('HD-2291');
});

test('sequential-conversation-memory falls back to a summary when there is no explicit reference', function () {
    $swarmClass = $this->namespace.'\\Ai\\Swarms\\SequentialConversationMemory\\ConversationMemory';

    $response = $swarmClass::make()->prompt('the invoice download button does nothing when I click it');

    // The remembered subject is a short summary of the message; step two recalls
    // it and names it in the reply, again reaching it only through memory.
    expect($response->steps[0]->output)->not->toContain('invoice download button')
        ->and((string) $response)->toContain('invoice download button');
});

test('sequential-conversation-memory runner command reflects the recalled subject', function () {
    $commandClass = $this->namespace.'\\Console\\Commands\\SwarmExampleConversationMemoryCommand';

    expect(class_exists($commandClass))->toBeTrue();

    Artisan::registerCommand(new $commandClass);

    $exit = Artisan::call('swarm:example:conversation-memory', ['message' => 'Re-opening case AB-7788 about duplicate invoices.']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('AB-7788');
});
