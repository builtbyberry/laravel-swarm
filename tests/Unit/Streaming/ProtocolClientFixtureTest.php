<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Protocols\AgentUserInteractionClient;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Protocols\VercelDataStreamClient;
use RuntimeException;

/** @param list<array<string, mixed>|string> $payloads */
function malformedProtocolStream(array $payloads): string
{
    return implode("\n\n", array_map(
        static fn (array|string $payload): string => 'data: '.(is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR)),
        $payloads,
    ))."\n\n";
}

test('Vercel client rejects malformed DONE terminator sequences', function (array $payloads, string $message): void {
    expect(fn () => VercelDataStreamClient::consume(malformedProtocolStream($payloads)))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'mid-stream DONE' => [[
        ['type' => 'start'],
        ['type' => 'start-step'],
        '[DONE]',
        ['type' => 'error'],
    ], 'frame after its required terminator'],
    'duplicate DONE' => [[
        ['type' => 'start'],
        ['type' => 'start-step'],
        ['type' => 'error'],
        '[DONE]',
        '[DONE]',
    ], 'required terminator more than once'],
]);

test('AG-UI client rejects malformed terminal sequences', function (array $events, string $message): void {
    expect(fn () => AgentUserInteractionClient::consume(malformedProtocolStream($events)))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'duplicate terminals' => [[
        ['type' => 'RUN_STARTED'],
        ['type' => 'STEP_STARTED'],
        ['type' => 'RUN_ERROR'],
        ['type' => 'RUN_ERROR'],
    ], 'more than one protocol terminal'],
    'event after terminal' => [[
        ['type' => 'RUN_STARTED'],
        ['type' => 'STEP_STARTED'],
        ['type' => 'RUN_ERROR'],
        ['type' => 'CUSTOM'],
    ], 'event after its protocol terminal'],
]);
