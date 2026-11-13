<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Support\AgentResponseOutput;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;

function structuredOutputResponse(array $structured, string $text): StructuredAgentResponse
{
    return new StructuredAgentResponse('invocation', $structured, $text, new TextUsage, new Meta('fake', 'test'));
}

it('stringifies a populated structured payload exactly as the response does', function () {
    $response = structuredOutputResponse(['result' => 'ok', 'items' => [1, 2]], 'text the payload was decoded from');

    expect(AgentResponseOutput::text($response))
        ->toBe('{"result":"ok","items":[1,2]}')
        ->toBe((string) $response);
});

it('uses the text when a structured payload is empty and the text is not', function (string $text) {
    expect(AgentResponseOutput::text(structuredOutputResponse([], $text)))->toBe($text);
})->with([
    'invalid json' => ['this is not json'],
    'zero' => ['0'],
    'whitespace' => ['  '],
    'an empty object' => ['{}'],
]);

it('keeps an empty structured payload with no text as an empty json array', function () {
    expect(AgentResponseOutput::text(structuredOutputResponse([], '')))->toBe('[]');
});

it('uses the text of a plain response', function () {
    $response = new AgentResponse('invocation', 'plain words', new TextUsage, new Meta('fake', 'test'));

    expect(AgentResponseOutput::text($response))->toBe('plain words');
});
