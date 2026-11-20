<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCitation;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\HttpCitationAgent;
use Composer\InstalledVersions;
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

/**
 * Runs HttpCitationAgent against a faked Anthropic response and returns the
 * swarm result with the citation events it streamed (none for prompt()).
 *
 * @param  list<array<string, mixed>>  $providerBlocks  blocks Anthropic returns before the answer text
 * @param  list<array<string, mixed>>  $inlineCitations  citations attached to the answer text, in order
 * @return array{0: object, 1: list<SwarmCitation>}
 */
function anthropicCitationRun(array $providerBlocks, array $inlineCitations, bool $streamed): array
{
    config()->set('ai.default', 'anthropic');
    $response = ['id' => 'msg-native', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-5', 'content' => [
        ...$providerBlocks,
        ['type' => 'text', 'text' => 'answer', 'citations' => $inlineCitations],
    ], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 2, 'output_tokens' => 3]];

    if (! $streamed) {
        Http::fake(['*' => Http::response($response)]);

        return [app(SwarmRunner::class)->agent(new HttpCitationAgent)->prompt('task'), []];
    }

    // The stream carries the same blocks in the same order as the prompt response.
    $events = [['type' => 'message_start', 'message' => array_replace($response, ['content' => []])]];
    foreach ($providerBlocks as $index => $block) {
        $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => $block];
        $events[] = ['type' => 'content_block_stop', 'index' => $index];
    }
    $text = count($providerBlocks);
    $events[] = ['type' => 'content_block_start', 'index' => $text, 'content_block' => ['type' => 'text', 'text' => '']];
    $events[] = ['type' => 'content_block_delta', 'index' => $text, 'delta' => ['type' => 'text_delta', 'text' => 'answer']];
    foreach ($inlineCitations as $citation) {
        $events[] = ['type' => 'content_block_delta', 'index' => $text, 'delta' => ['type' => 'citations_delta', 'citation' => $citation]];
    }
    $events[] = ['type' => 'content_block_stop', 'index' => $text];
    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]];
    $events[] = ['type' => 'message_stop'];
    Http::fake(['*' => Http::response(implode('', array_map(fn ($event) => 'event: '.$event['type']."\n".'data: '.json_encode($event)."\n\n", $events)))]);
    $stream = app(SwarmRunner::class)->agent(new HttpCitationAgent)->stream('task');
    $citationEvents = collect(iterator_to_array($stream))->whereInstanceOf(SwarmCitation::class)->values()->all();

    return [$stream->streamedResponse, $citationEvents];
}

/**
 * Every retained citation belongs to exactly one streamed citation event, in order.
 *
 * @param  list<SwarmCitation>  $events
 */
function expectCitationsMatchEvents(object $result, array $events): void
{
    expect(array_map(fn ($citation) => [$citation->eventId, $citation->url, $citation->title], $result->citations))
        ->toBe(array_map(fn (SwarmCitation $event) => [$event->id, $event->citationEvidence->items[0]->url, $event->citationEvidence->items[0]->title], $events));
    expect(array_unique(array_map(fn (SwarmCitation $event) => $event->id, $events)))->toHaveCount(count($events));
}

/*
 * Swarm reports the sources laravel/ai reports; it does not read Anthropic's
 * payload itself. On laravel/ai 1.0.0, 1.4.0 and 1.4.1 that is the fetched page
 * and the source the answer cites. The uncited result in this fixture's
 * `content` list is not reported on any of the three; on 1.4.1 laravel/ai
 * retains that payload in the step's provider-tool calls.
 */
it('preserves the Anthropic sources laravel/ai reports and adds none of its own', function (bool $streamed) {
    $cited = ['type' => 'web_search_result_location', 'url' => 'https://source.example/anthropic', 'title' => 'Native source'];
    [$result, $events] = anthropicCitationRun([
        ['type' => 'web_search_tool_result', 'tool_use_id' => 'search', 'content' => [
            ['type' => 'web_search_result', 'url' => 'https://source.example/search-only', 'title' => 'Search result'],
            ['type' => 'web_search_result', 'url' => $cited['url'], 'title' => $cited['title']],
        ]],
        ['type' => 'web_fetch_tool_result', 'tool_use_id' => 'fetch', 'content' => ['type' => 'web_fetch_result', 'url' => 'https://source.example/fetched', 'content' => ['title' => 'Fetched page']]],
    ], [$cited], $streamed);

    expect(array_map(fn ($citation) => [$citation->url, $citation->title, $citation->startIndex, $citation->endIndex], $result->citations))
        ->toBe([
            ['https://source.example/fetched', 'Fetched page', null, null],
            ['https://source.example/anthropic', 'Native source', null, null],
        ])
        ->and($result->citationEvidence->status)->toBe('available');

    if ($streamed) {
        expect($events)->toHaveCount(2);
        expectCitationsMatchEvents($result, $events);
    }
})->with([false, true]);

/*
 * For a URL the answer cites twice, stream() emitted one occurrence on
 * laravel/ai 1.4.1 and two on the measured 1.0.0 and 1.4.0 installs. For these
 * fixtures Swarm preserves each distinct citation event laravel/ai emits.
 * prompt() reported the URL once on 1.0.0, 1.4.0 and 1.4.1.
 */
it('reports a source the answer cites twice as often as laravel/ai reports it', function (bool $streamed) {
    $cited = ['type' => 'web_search_result_location', 'url' => 'https://source.example/anthropic', 'title' => 'Native source'];
    [$result, $events] = anthropicCitationRun([], [$cited, $cited], $streamed);

    $streamsEachMention = version_compare((string) InstalledVersions::getVersion('laravel/ai'), '1.4.1', '<');
    $occurrences = $streamed && $streamsEachMention ? 2 : 1;

    expect(array_map(fn ($citation) => [$citation->url, $citation->title], $result->citations))
        ->toBe(array_fill(0, $occurrences, ['https://source.example/anthropic', 'Native source']));

    if ($streamed) {
        expect($events)->toHaveCount($occurrences);
        expectCitationsMatchEvents($result, $events);
    }
})->with([false, true]);
