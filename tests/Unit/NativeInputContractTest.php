<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManager;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManifest;
use BuiltByBerry\LaravelSwarm\Support\NativeInputRecipient;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Messages\UserMessage;

test('legacy run context payloads retain their exact wire shape', function () {
    $payload = RunContext::fromTask(['topic' => 'Laravel'])->toQueuePayload();

    expect(array_keys($payload))->toBe(['run_id', 'input', 'data', 'metadata', 'artifacts'])
        ->and($payload['input'])->toBe('{"topic":"Laravel"}');
});

test('native message selection keeps topology text independent from attachments', function () {
    config()->set('swarm.native_inputs.enabled', true);

    $context = RunContext::fromTask('legacy')->withAgentInput(
        new UserMessage('original question', [new Base64Image(base64_encode('pixels'), 'image/png')]),
        [NativeInputRecipient::sequential(1, attachments: [0])->withInvocation('openai', 'gpt-test', 17)],
    );

    app(NativeInputManager::class)->admit($context, Topology::Sequential, ExecutionMode::Run);
    $invocation = $context->nativeInvocation('sequential:1', 'predecessor answer');

    expect($invocation->prompt)->toBeInstanceOf(UserMessage::class)
        ->and($invocation->prompt->content)->toBe('predecessor answer')
        ->and($invocation->prompt->attachments)->toHaveCount(1)
        ->and($invocation->provider)->toBe('openai')
        ->and($invocation->model)->toBe('gpt-test')
        ->and($invocation->timeout)->toBe(17)
        ->and($context->nativeInvocation('sequential:0', 'entry text')->prompt)->toBe('entry text');
});

test('parallel attachments require explicit recipients', function () {
    config()->set('swarm.native_inputs.enabled', true);
    $context = RunContext::fromTask(new UserMessage('inspect', [new Base64Image(base64_encode('pixels'))]));

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Run))
        ->toThrow(SwarmException::class, 'require explicit slot or node recipients');
});

test('recipient declarations fail closed for missing attachments and duplicate identities', function (array $recipients, string $message) {
    config()->set('swarm.native_inputs.enabled', true);
    $context = RunContext::fromTask(new UserMessage('inspect', [new Base64Image(base64_encode('pixels'))]))
        ->withAgentInput(
            new UserMessage('inspect', [new Base64Image(base64_encode('pixels'))]),
            $recipients,
        );

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Run))
        ->toThrow(SwarmException::class, $message);
})->with([
    'missing attachment' => [[NativeInputRecipient::parallel(0, attachments: [1])], 'selects missing attachment [1]'],
    'duplicate recipient' => [[NativeInputRecipient::parallel(0), NativeInputRecipient::parallel(0)], 'declared more than once'],
]);

test('slot recipients must identify an executable agent', function () {
    config()->set('swarm.native_inputs.enabled', true);

    $context = RunContext::fromTask(new UserMessage('inspect'))
        ->withAgentInput(new UserMessage('inspect'), [NativeInputRecipient::sequential(99)]);

    app(NativeInputManager::class)->admit($context, Topology::Sequential, ExecutionMode::Run);

    expect(fn () => $context->assertNativeSlotRecipients('sequential:', 2))
        ->toThrow(SwarmException::class, 'does not identify an executable agent slot');
});

test('native recipient descriptors reject invalid public values', function (Closure $make, string $message) {
    expect($make)->toThrow(SwarmException::class, $message);
})->with([
    'text source' => [fn () => new NativeInputRecipient('sequential:0', 'invented'), 'textSource'],
    'attachment index' => [fn () => new NativeInputRecipient('sequential:0', attachments: [-1]), 'non-negative'],
    'empty identity' => [fn () => new NativeInputRecipient(''), 'cannot be empty'],
    'timeout' => [fn () => new NativeInputRecipient('sequential:0', timeout: 0), 'positive integer'],
    'negative slot' => [fn () => NativeInputRecipient::sequential(-1), 'non-negative'],
    'empty node' => [fn () => NativeInputRecipient::generatedNode(' '), 'cannot be empty'],
]);

test('recipient topology and generated route identities fail closed', function () {
    config()->set('swarm.native_inputs.enabled', true);
    $wrongTopology = RunContext::fromTask(new UserMessage('inspect'))
        ->withAgentInput(new UserMessage('inspect'), [NativeInputRecipient::parallel(0)]);

    expect(fn () => app(NativeInputManager::class)->admit($wrongTopology, Topology::Sequential, ExecutionMode::Run))
        ->toThrow(SwarmException::class, 'does not belong to the [sequential] topology');

    $missingNode = RunContext::fromTask(new UserMessage('inspect'))
        ->withAgentInput(new UserMessage('inspect'), [NativeInputRecipient::generatedNode('missing')]);

    expect(fn () => $missingNode->assertNativeNodeRecipients('generated:', ['worker']))
        ->toThrow(SwarmException::class, 'does not exist in the validated route plan');
});

test('operational manifests reject malformed attachment and recipient descriptors', function (array $payload, string $message) {
    expect(fn () => NativeInputManifest::fromArray($payload))
        ->toThrow(SwarmException::class, $message);
})->with([
    'attachment' => [['attachments' => [['type' => 'invented']]], 'unsupported attachment descriptor'],
    'recipient' => [['recipients' => ['invented']], 'invalid recipient descriptor'],
]);
