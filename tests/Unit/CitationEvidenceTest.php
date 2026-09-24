<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Persistence\CitationEvidenceCodec;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidenceLimits;
use BuiltByBerry\LaravelSwarm\Responses\SwarmCitation;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\NativeCitationEvidence;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationStaticSwarm;
use Illuminate\Config\Repository;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Streaming\Events\Citation;

function sourceEvidence(): CitationEvidence
{
    return new CitationEvidence([new SwarmCitation('https://example.com/α', 'Ω title', 'run', 0, 'Agent', 0, 8)], CitationEvidence::AVAILABLE);
}

it('distinguishes supplied-empty unknown withheld and malformed evidence', function () {
    expect(CitationEvidence::available()->status)->toBe('available')
        ->and(CitationEvidence::fromArray([])->status)->toBe('unknown')
        ->and((new SwarmResponse('legacy'))->citationEvidence->status)->toBe('unknown')
        ->and((new CitationEvidence([], CitationEvidence::OMITTED))->toArray())->not->toHaveKey('citations')
        ->and(CitationEvidence::fromArray(['citation_status' => 'available', 'citation_reasons' => [], 'citations' => [['url' => 42]]])->status)->toBe('unavailable');
    expect(fn () => new CitationEvidence(sourceEvidence()->items, CitationEvidence::REDACTED))->toThrow(InvalidArgumentException::class);
    expect(fn () => new CitationEvidence([], CitationEvidence::PARTIAL))->toThrow(InvalidArgumentException::class);
});

it('marks heterogeneous contributing evidence partial without revealing hidden counts', function (string $status) {
    $combined = CitationEvidence::combine([sourceEvidence(), new CitationEvidence([], $status)]);
    expect($combined->status)->toBe('partial')->and($combined->items)->toHaveCount(1)
        ->and($combined->reasons)->toBe(['contributing_'.$status])
        ->and($combined->toArray())->not->toHaveKeys(['hidden_count', 'withheld_sources']);
})->with(['unknown', 'redacted', 'omitted', 'unavailable']);

it('bounds native extraction by complete records and preserves explicit partial state', function () {
    config()->set('swarm.citations.max_count', 1);
    $native = new AgentResponse('inv', 'text', new TextUsage, new Meta('fixture', 'model', collect([
        new UrlCitation('https://example.com/first', 'first', 1, 7), new UrlCitation('https://example.com/second', 'second'),
    ])));
    $evidence = app(NativeCitationEvidence::class)->response($native, 'run', 0, 'Agent');
    expect($evidence->items)->toHaveCount(1)->and($evidence->status)->toBe('partial')->and($evidence->reasons)->toBe(['limit']);
    config()->set('swarm.citations.max_bytes', 180);
    $bounded = app(CitationEvidenceLimits::class)->apply(sourceEvidence());
    expect($bounded->items)->toBe([])->and($bounded->status)->toBe('partial');
});

it('reconciles occurrences one for one and never erases event sources with empty terminal meta', function () {
    $mapper = app(NativeCitationEvidence::class);
    $source = new UrlCitation('https://example.com', 'Title', 1, 8);
    $first = $mapper->event((new Citation('event1', 'message', $source, 123))->withInvocationId('inv'), 'run', 0, 'Agent');
    $second = $mapper->event((new Citation('event2', 'message', $source, 124))->withInvocationId('inv'), 'run', 0, 'Agent');
    $events = $mapper->append($mapper->append(CitationEvidence::available(), $first), $second);
    expect($mapper->append($events, $first)->items)->toHaveCount(2);
    $terminal = $mapper->response(new AgentResponse('inv', 'text', new TextUsage, new Meta(citations: collect([$source, $source]))), 'run', 0, 'Agent');
    $result = $mapper->reconcile($events, $terminal);
    expect($result->items)->toHaveCount(2)->and($result->items[0]->eventId)->toBe('event1')->and($result->items[1]->eventId)->toBe('event2')
        ->and($mapper->reconcile($events, CitationEvidence::available())->toArray())->toBe($events->toArray());
});

it('retains new evidence in typed end-event wire payloads while old payloads read unknown', function () {
    $event = new SwarmStepEnd('event', 'run', 0, 'Agent', 'agent', 'out', 1, [], 123, sourceEvidence());
    expect(SwarmStreamEvent::fromArray($event->toArray())->toArray())->toBe($event->toArray());
    $old = $event->toArray();
    unset($old['citations'], $old['citation_status'], $old['citation_reasons']);
    expect(SwarmStreamEvent::fromArray($old)->citationEvidence->status)->toBe('unknown');
});

it('bounds normalized fixtures and keeps fake streaming lazy with distinct per-step sources', function () {
    $earlier = sourceEvidence();
    $final = new CitationEvidence([new SwarmCitation('https://example.com/final', null, 'fixture', 1, 'Final')], CitationEvidence::AVAILABLE);
    $response = new SwarmResponse('final', [
        new SwarmStep('Earlier', 'input', 'draft', citationEvidence: $earlier),
        new SwarmStep('Final', 'draft', 'final', citationEvidence: $final),
    ], citationEvidence: $final);
    $fake = new SwarmFake(CitationStaticSwarm::class, [$response]);
    $stream = $fake->stream('task');
    $fake->assertNeverStreamed();
    iterator_to_array($stream);
    expect($stream->streamedResponse->steps[0]->citations[0]->url)->toBe('https://example.com/α')
        ->and($stream->streamedResponse->citations[0]->url)->toBe('https://example.com/final');
    $fake->assertStreamed('task');
    config()->set('swarm.citations.max_count', 0);
    $bounded = (new SwarmFake(CitationStaticSwarm::class, [$response]))->prompt('task');
    expect($bounded->citationEvidence->status)->toBe('partial')->and($bounded->citations)->toBe([])
        ->and($bounded->steps[0]->citations)->toBe([]);
});

it('bounds untrusted persisted evidence and rejects malformed sealed payloads', function () {
    config()->set('swarm.citations.max_count', 0);
    $codec = app(CitationEvidenceCodec::class);
    $decoded = $codec->decode(json_encode(sourceEvidence()->toArray()));
    expect($decoded->status)->toBe('partial')->and($decoded->items)->toBe([])
        ->and($codec->decode('{bad')->reasons)->toBe(['malformed']);
});

it('writes exact source fields on citation step-end and stream-end protocol frames', function () {
    $evidence = sourceEvidence();
    $frames = [
        new SwarmStepEnd('step', 'run', 0, 'Agent', 'agent', 'out', 1, [], 123, $evidence),
        new SwarmStreamEnd('end', 'run', 'out', [], [], 123, $evidence),
        new BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCitation('source', 'run', 0, 'Agent', 'message', 123, $evidence),
    ];
    foreach ($frames as $frame) {
        $payload = $frame->toArray();
        expect($payload['citation_status'])->toBe('available')->and($payload['citation_reasons'])->toBe([])
            ->and($payload['citations'])->toHaveCount(1)
            ->and($payload['citations'][0])->toBe([
                'type' => 'url', 'url' => 'https://example.com/α', 'title' => 'Ω title',
                'run_id' => 'run', 'step_index' => 0, 'agent_class' => 'Agent', 'node_id' => null,
                'invocation_id' => null, 'message_id' => null, 'event_id' => null, 'timestamp' => null,
                'start_index' => 0, 'end_index' => 8, 'range_domain' => 'original_agent_output',
            ]);
        $decoded = SwarmStreamEvent::fromArray($payload);
        expect($decoded->citationEvidence->items[0]->startIndex)->toBe(0)
            ->and($decoded->citationEvidence->items[0]->endIndex)->toBe(8);
    }
});

it('rejects incomplete evidence envelopes without fabricating supplied-empty sources', function (array $payload) {
    foreach ([CitationEvidence::fromArray($payload), app(CitationEvidenceCodec::class)->decode(json_encode($payload))] as $decoded) {
        expect($decoded->status)->toBe('unavailable')->and($decoded->reasons)->toBe(['malformed']);
    }
    expect(CitationEvidence::fromArray(CitationEvidence::available()->toArray())->status)->toBe('available')
        ->and(CitationEvidence::fromArray(['citation_status' => 'omitted', 'citation_reasons' => []])->status)->toBe('omitted')
        ->and(CitationEvidence::fromArray([])->status)->toBe('unknown');
})->with([
    [['citation_status' => 'available', 'citation_reasons' => []]],
    [['citation_status' => 'available', 'citation_reasons' => [], 'citations' => null]],
]);

it('fills missing supplied provenance on matched stream occurrences without replacing event identity', function () {
    $mapper = app(NativeCitationEvidence::class);
    $source = new UrlCitation('https://example.com', 'Title', 1, 8);
    $event = $mapper->event(new Citation('event', 'message', $source, 123), 'run', 0, 'Agent');
    $terminal = $mapper->response(new AgentResponse('known-invocation', 'text', new TextUsage, new Meta(citations: collect([$source]))), 'run', 0, 'Agent', 'node');
    $result = $mapper->reconcile($event, $terminal);
    expect($result->items)->toHaveCount(1)->and($result->items[0]->invocationId)->toBe('known-invocation')
        ->and($result->items[0]->nodeId)->toBe('node')->and($result->items[0]->eventId)->toBe('event')
        ->and($result->items[0]->messageId)->toBe('message')->and($result->items[0]->timestamp)->toBe(123);
    $different = $mapper->event((new Citation('other', 'message', $source, 124))->withInvocationId('other-invocation'), 'run', 0, 'Agent');
    expect($mapper->reconcile($different, $terminal)->items)->toHaveCount(2);
});

it('uses the injected citation limiter when normalizing response payloads', function () {
    $limits = new CitationEvidenceLimits(new Repository(['swarm' => ['citations' => ['max_count' => 0]]]));
    app()->instance(CitationEvidenceLimits::class, $limits);
    $response = app(SwarmPayloadLimits::class)->response(new SwarmResponse('out', citationEvidence: sourceEvidence()));
    expect($response->citationEvidence->status)->toBe('partial')->and($response->citations)->toBe([]);
});

it('counts exact encoded bytes including separators independently for each invocation', function () {
    $one = sourceEvidence()->items[0];
    $two = new SwarmCitation('https://example.com/β', 'quoted " Ω', 'run', 0, 'Agent');
    $other = new SwarmCitation($one->url, $one->title, 'run', 1, 'Agent', 0, 8);
    $pair = new CitationEvidence([$one, $two], CitationEvidence::AVAILABLE);
    $budget = strlen(json_encode($pair->toArray(), JSON_THROW_ON_ERROR)) + 32;
    config()->set('swarm.citations.max_bytes', $budget);
    $limits = app(CitationEvidenceLimits::class);
    expect($limits->apply($pair)->toArray())->toBe($pair->toArray());
    config()->set('swarm.citations.max_bytes', $budget - 1);
    $result = $limits->apply(new CitationEvidence([$one, $two, $other], CitationEvidence::AVAILABLE));
    expect($result->status)->toBe('partial')->and($result->items)->toBe([$one, $other])->and($result->reasons)->toBe(['limit']);
});
