<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCitation;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\HttpCitationAgent;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'fixture');
    config()->set('ai.providers.anthropic.key', 'fixture');
    Http::preventStrayRequests();
});

it('preserves actual native OpenAI HTTP citations for prompt and stream', function (bool $streamed) {
    config()->set('ai.default', 'openai');
    $annotation = ['type' => 'url_citation', 'url' => 'https://source.example/openai', 'title' => 'Native Ω', 'start_index' => 2, 'end_index' => 9];
    $message = ['type' => 'message', 'id' => 'msg-native', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'answer', 'annotations' => [$annotation]]]];
    $response = ['id' => 'resp-native', 'model' => 'gpt-4.1-mini', 'status' => 'completed', 'output' => [$message], 'usage' => ['input_tokens' => 2, 'output_tokens' => 3]];
    if ($streamed) {
        $events = [
            ['type' => 'response.created', 'response' => $response],
            ['type' => 'response.output_item.added', 'item' => $message, 'output_index' => 0],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg-native', 'delta' => 'answer'],
            ['type' => 'response.output_text.annotation.added', 'item_id' => 'msg-native', 'annotation' => $annotation],
            ['type' => 'response.output_text.done', 'item_id' => 'msg-native', 'text' => 'answer'],
            ['type' => 'response.completed', 'response' => $response],
        ];
        Http::fake(['*' => Http::response(implode('', array_map(fn ($event) => 'data: '.json_encode($event)."\n\n", $events)))]);
        $stream = app(SwarmRunner::class)->agent(new HttpCitationAgent)->stream('task');
        $swarmEvents = collect(iterator_to_array($stream));
        $result = $stream->streamedResponse;
        $event = $swarmEvents->whereInstanceOf(SwarmCitation::class)->sole();
        expect($event->invocationId)->not->toBeNull()->and($event->id)->not->toBeEmpty()
            ->and($result->citations[0]->eventId)->toBe($event->id);
    } else {
        Http::fake(['*' => Http::response($response)]);
        $result = app(SwarmRunner::class)->agent(new HttpCitationAgent)->prompt('task');
    }
    expect($result->citations)->toHaveCount(1)->and($result->citations[0]->url)->toBe($annotation['url'])
        ->and($result->citations[0]->title)->toBe('Native Ω')->and($result->citations[0]->startIndex)->toBe(2)
        ->and($result->citations[0]->endIndex)->toBe(9)->and($result->citationEvidence->status)->toBe('available');
})->with([false, true]);

it('preserves actual native Anthropic HTTP search sources and streamed citations', function (bool $streamed) {
    config()->set('ai.default', 'anthropic');
    $citation = ['type' => 'web_search_result_location', 'url' => 'https://source.example/anthropic', 'title' => 'Native source'];
    $response = ['id' => 'msg-native', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-5', 'content' => [
        ['type' => 'text', 'text' => 'answer', 'citations' => [$citation]],
        ['type' => 'web_search_tool_result', 'tool_use_id' => 'search', 'search_results' => [['url' => 'https://source.example/search-only', 'title' => 'Search result']]],
    ], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 2, 'output_tokens' => 3]];
    if ($streamed) {
        $events = [
            ['type' => 'message_start', 'message' => array_replace($response, ['content' => []])],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'answer']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'citations_delta', 'citation' => $citation]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]],
            ['type' => 'message_stop'],
        ];
        Http::fake(['*' => Http::response(implode('', array_map(fn ($event) => 'event: '.$event['type']."\n".'data: '.json_encode($event)."\n\n", $events)))]);
        $stream = app(SwarmRunner::class)->agent(new HttpCitationAgent)->stream('task');
        iterator_to_array($stream);
        $result = $stream->streamedResponse;
    } else {
        Http::fake(['*' => Http::response($response)]);
        $result = app(SwarmRunner::class)->agent(new HttpCitationAgent)->prompt('task');
        expect($result->citations[1]->url)->toBe('https://source.example/search-only');
    }
    expect($result->citations[0]->url)->toBe($citation['url'])->and($result->citations[0]->title)->toBe('Native source')
        ->and($result->citations[0]->startIndex)->toBeNull()->and($result->citations[0]->endIndex)->toBeNull();
})->with([false, true]);
