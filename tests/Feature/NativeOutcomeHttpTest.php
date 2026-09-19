<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\ApprovalTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\EffectTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome\NativeHttpAgent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('uses native HTTP middleware and options before rejecting pending outcomes', function (string $mode) {
    config()->set('ai.providers.openai.key', 'test-key');
    ApprovalTool::$calls = 0;
    EffectTool::$calls = 0;
    Event::fake([SwarmCompleted::class, SwarmStepCompleted::class]);
    $call = ['type' => 'function_call', 'id' => 'item', 'call_id' => 'approval-secret', 'name' => 'ApprovalTool', 'arguments' => '{"value":"argument-secret"}'];
    $effect = array_replace($call, ['id' => 'effect-item', 'call_id' => 'effect-call', 'name' => 'EffectTool']);
    $response = ['id' => 'resp_test', 'model' => 'gpt-4.1-mini', 'status' => 'completed', 'output' => [$effect, $call], 'usage' => ['input_tokens' => 2, 'output_tokens' => 3]];
    $body = $mode === 'prompt' ? $response : implode('', array_map(
        fn ($data) => 'data: '.json_encode($data)."\n\n",
        [
            ['type' => 'response.created', 'response' => $response],
            ['type' => 'response.output_item.added', 'item' => $effect, 'output_index' => 0],
            ['type' => 'response.function_call_arguments.done', 'item_id' => 'effect-item', 'arguments' => $effect['arguments']],
            ['type' => 'response.output_item.added', 'item' => $call, 'output_index' => 1],
            ['type' => 'response.function_call_arguments.done', 'item_id' => 'item', 'arguments' => $call['arguments']],
            ['type' => 'response.completed', 'response' => $response],
        ],
    ));
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($body) {
        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and($request['model'])->toBe('gpt-4.1-mini')
            ->and($request['max_output_tokens'])->toBe(321)
            ->and(json_encode($request['input']))->toContain('revised task')
            ->and($request['tools'][0]['name'])->toBe('ApprovalTool');

        return Http::response($body, 200);
    });
    expect(function () use ($mode) {
        $response = app(SwarmRunner::class)->agent(new NativeHttpAgent)->{$mode}('task');
        if ($mode === 'stream') {
            iterator_to_array($response);
        }
    })->toThrow(UnsupportedNativeApprovalException::class);
    Http::assertSentCount(1);
    expect(ApprovalTool::$calls)->toBe(0)
        ->and(EffectTool::$calls)->toBe(1);
    Event::assertNotDispatched(SwarmStepCompleted::class);
    Event::assertNotDispatched(SwarmCompleted::class);
})->with(['prompt', 'stream']);

it('preserves successful native HTTP output and usage', function () {
    config()->set('ai.providers.openai.key', 'test-key');
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response([
        'id' => 'completed', 'model' => 'gpt-4.1-mini', 'status' => 'completed',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'native-output']]]],
        'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
    ])]);
    $response = app(SwarmRunner::class)->agent(new NativeHttpAgent)->prompt('task');
    expect($response->output)->toBe('native-output')
        ->and($response->usage['prompt_tokens'])->toBe(2)
        ->and($response->usage['completion_tokens'])->toBe(3);
    Http::assertSentCount(1);
});
