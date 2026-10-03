<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManager;
use BuiltByBerry\LaravelSwarm\Support\NativeInputRecipient;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\NativeHttpAgent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Messages\UserMessage;

test('native and through Swarm messages produce the same Laravel AI wire input', function (object $attachment) {
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('swarm.native_inputs.enabled', true);

    $requests = [];
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$requests) {
        $requests[] = $request->data();

        return Http::response([
            'id' => 'completed',
            'model' => 'gpt-4.1-mini',
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'native-output']]]],
            'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
        ]);
    });

    $message = new UserMessage('inspect this file', [$attachment]);
    (new NativeHttpAgent)->prompt($message);
    app(SwarmRunner::class)->agent(new NativeHttpAgent)->prompt($message);

    expect($requests)->toHaveCount(2)
        ->and($requests[1]['input'])->toBe($requests[0]['input']);
})->with([
    'image' => fn () => new Base64Image(base64_encode('image-bytes'), 'image/png'),
    'document' => fn () => new Base64Document(base64_encode('document-bytes'), 'text/plain'),
]);

test('reconstructed Swarm native input preserves Laravel AI wire input', function (object $attachment) {
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('swarm.native_inputs.enabled', true);
    config()->set('swarm.native_inputs.disk', 'native-wire-test');
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    Storage::fake('native-wire-test');

    $requests = [];
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$requests) {
        $requests[] = $request->data();

        return Http::response([
            'id' => 'completed',
            'model' => 'gpt-4.1-mini',
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'native-output']]]],
            'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
        ]);
    });

    $message = new UserMessage('inspect this file', [$attachment]);
    (new NativeHttpAgent)->prompt($message);

    $context = RunContext::fromTask($message)->withAgentInput(
        $message,
        [NativeInputRecipient::parallel(0, textSource: 'original', attachments: [0])],
    );
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $reconstructed = RunContext::fromPayload($context->toQueuePayload())
        ->nativeInvocation('parallel:0', 'topology')->prompt;
    (new NativeHttpAgent)->prompt($reconstructed);

    expect($requests)->toHaveCount(2)
        ->and($requests[1]['input'])->toBe($requests[0]['input']);
})->with([
    'recovered image' => fn () => (new Base64Image(base64_encode('image-bytes'), 'image/png'))->as('image.png'),
    'recovered document' => fn () => (new Base64Document(base64_encode('document-bytes'), 'text/plain'))->as('document.txt'),
]);
