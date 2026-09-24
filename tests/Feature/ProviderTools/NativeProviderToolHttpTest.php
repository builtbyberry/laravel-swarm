<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolEvent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\HttpCitationAgent;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Providers\Tools\CodeExecution;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'fixture');
    config()->set('ai.providers.anthropic.key', 'fixture');
    Http::preventStrayRequests();
});

it('preserves provider activity from real native OpenAI wire parsing without tool result fabrication', function () {
    config()->set('ai.default', 'openai');
    $response = ['id' => 'resp', 'model' => 'gpt-4.1-mini', 'status' => 'completed', 'output' => [], 'usage' => ['input_tokens' => 2, 'output_tokens' => 3]];
    $progress = ['type' => 'response.web_search_call.searching', 'item_id' => 'search', 'query' => 'canary-query'];
    $item = ['type' => 'web_search_call', 'id' => 'search', 'status' => 'failed', 'action' => ['query' => 'canary-query']];
    $events = [['type' => 'response.created', 'response' => $response], $progress,
        ['type' => 'response.output_item.done', 'item' => $item],
        ['type' => 'response.completed', 'response' => $response]];
    Http::fake(['*' => Http::response(implode('', array_map(fn ($e) => 'data: '.json_encode($e)."\n\n", $events)))]);
    $stream = app(SwarmRunner::class)->agent(new HttpCitationAgent)->stream('task');
    $all = collect(iterator_to_array($stream));
    $tools = $all->whereInstanceOf(SwarmProviderToolEvent::class)->values();
    expect($tools)->toHaveCount(2)->and($tools[0]->itemId)->toBe('search')
        ->and($tools[0]->provider)->toBe('openai')->and($tools[0]->providerType)->toBe('web_search_call')
        ->and($tools[0]->providerStatus)->toBe('searching')->and($tools[0]->payload->data)->toBe($progress)
        ->and($tools[1]->providerStatus)->toBe('completed')->and($tools[1]->payload->data)->toBe($item)
        ->and($tools[0]->invocationId)->not->toBeNull()->and($tools[1]->invocationId)->toBe($tools[0]->invocationId)
        ->and($tools[0]->id)->not->toBeEmpty()->and($tools[0]->timestamp)->toBeGreaterThan(0)
        ->and($all->filter(fn ($e) => in_array($e->type(), ['swarm_tool_call', 'swarm_tool_result'])))->toBeEmpty();
    Http::assertSentCount(1);
});

it('preserves native Anthropic started completed and failed result data distinctly', function () {
    config()->set('ai.default', 'anthropic');
    $start = ['type' => 'server_tool_use', 'id' => 'server-item', 'name' => 'web_search', 'input' => []];
    $result = ['type' => 'web_search_tool_result', 'tool_use_id' => 'server-item', 'content' => ['type' => 'web_search_tool_result_error', 'error_code' => 'denied']];
    $events = [
        ['type' => 'message_start', 'message' => ['id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-5', 'content' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => $start],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":"canary-query"}']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => $result],
        ['type' => 'content_block_stop', 'index' => 1],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]], ['type' => 'message_stop'],
    ];
    Http::fake(['*' => Http::response(implode('', array_map(fn ($e) => 'event: '.$e['type']."\n".'data: '.json_encode($e)."\n\n", $events)))]);
    $stream = app(SwarmRunner::class)->agent(new HttpCitationAgent)->stream('task');
    $tools = collect(iterator_to_array($stream))->whereInstanceOf(SwarmProviderToolEvent::class)->values();
    expect($tools->pluck('providerStatus')->all())->toBe(['started', 'completed', 'result_received'])
        ->and($tools->pluck('itemId')->all())->toBe(['server-item', 'server-item', 'server-item'])
        ->and($tools[1]->payload->data['input'])->toBe(['query' => 'canary-query'])
        ->and($tools[2]->payload->data)->toBe($result)
        ->and($tools[0]->provider)->toBe('anthropic')->and($tools[0]->invocationId)->not->toBeNull();
    Http::assertSentCount(1);
});

it('preserves hosted code execution wire identity and capture policy without function results', function (bool $capture) {
    config()->set('ai.default', 'openai');
    config()->set('swarm.capture.outputs', $capture);
    $agent = new class extends HttpCitationAgent implements HasTools
    {
        public function tools(): iterable
        {
            return [new CodeExecution];
        }
    };
    $item = ['type' => 'code_interpreter_call', 'id' => 'code-item', 'status' => 'completed',
        'container_id' => 'container-fixture', 'code' => 'print("code-secret")',
        'outputs' => [['type' => 'logs', 'logs' => 'code-secret']]];
    $response = ['id' => 'code-response', 'model' => 'gpt-4.1-mini', 'status' => 'completed',
        'output' => [$item], 'usage' => ['input_tokens' => 2, 'output_tokens' => 3]];
    $progress = ['type' => 'response.code_interpreter_call_code.delta', 'item_id' => 'code-item', 'delta' => 'print("code-secret")'];
    $wire = [['type' => 'response.created', 'response' => $response], $progress,
        ['type' => 'response.output_item.done', 'item' => $item],
        ['type' => 'response.completed', 'response' => $response]];
    Http::fake(['*' => Http::response(implode('', array_map(fn ($event) => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n", $wire)))]);
    $stream = app(SwarmRunner::class)->agent($agent)->stream('execute hosted code');
    $all = collect(iterator_to_array($stream));
    $events = $all->whereInstanceOf(SwarmProviderToolEvent::class)->values();
    expect($events)->toHaveCount(2)
        ->and($events->pluck('itemId')->all())->toBe(['code-item', 'code-item'])
        ->and($events->pluck('providerType')->all())->toBe(['code_interpreter_call', 'code_interpreter_call'])
        ->and($events->pluck('providerStatus')->all())->toBe(['code_delta', 'completed'])
        ->and($events[0]->provider)->toBe('openai')
        ->and($events[0]->invocationId)->not->toBeNull()
        ->and($events[1]->invocationId)->toBe($events[0]->invocationId)
        ->and($events->pluck('id')->unique())->toHaveCount(2)
        ->and($events[0]->payload->status)->toBe($capture ? 'available' : 'redacted')
        ->and($events[0]->payload->data)->toBe($capture ? $progress : [])
        ->and($events[1]->payload->data)->toBe($capture ? $item : [])
        ->and($all->filter(fn ($event) => in_array($event->type(), ['swarm_tool_call', 'swarm_tool_result'])))->toBeEmpty();
    Http::assertSent(fn ($request) => $request['tools'] === [['type' => 'code_interpreter', 'container' => ['type' => 'auto']]]);
    Http::assertSentCount(1);
})->with([true, false]);
