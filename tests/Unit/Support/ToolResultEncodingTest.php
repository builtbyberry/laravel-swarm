<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\ToolResultEncoding;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;

/**
 * A tool result and its call arguments are both typed `mixed`, so either can be
 * a value JSON cannot represent. Both share the strict-encode crash path at the
 * snapshot `tool_calls` column and the streamed `swarm_tool_call` /
 * `swarm_tool_result` events, so both degrade to a typed placeholder rather than
 * throwing a `JsonException` up through a runner. These tests pin the
 * arguments-side coverage and prove every degrade boundary encodes without
 * re-throwing.
 */
$invalidUtf8 = "\xB1\x31\xFE"; // json_encode(..., JSON_THROW_ON_ERROR) rejects this.

test('unencodable arguments degrade to the arguments placeholder; encodable arguments pass through', function () use ($invalidUtf8) {
    $degraded = ToolResultEncoding::degradeToolArguments(['blob' => $invalidUtf8], 'docs.binary');

    expect($degraded)->toBe([
        ToolResultEncoding::UNENCODABLE_ARGUMENTS_MARKER => true,
        'tool' => 'docs.binary',
    ]);

    // The placeholder must itself never re-throw.
    expect(json_encode($degraded, JSON_THROW_ON_ERROR))->toBeString();

    // An encodable argument set is returned byte-identical.
    expect(ToolResultEncoding::degradeToolArguments(['query' => 'swarm'], 'docs.search'))
        ->toBe(['query' => 'swarm']);
});

test('degradeToolCalls degrades both result and arguments, leaving other fields intact', function () use ($invalidUtf8) {
    $entries = [[
        'id' => 'call-1',
        'name' => 'docs.binary',
        'arguments' => ['path' => $invalidUtf8],
        'result' => ['blob' => $invalidUtf8],
        'result_id' => 'result-1',
    ]];

    $degraded = ToolResultEncoding::degradeToolCalls($entries);

    expect($degraded[0]['arguments'])->toBe([
        ToolResultEncoding::UNENCODABLE_ARGUMENTS_MARKER => true,
        'tool' => 'docs.binary',
    ]);
    expect($degraded[0]['result'])->toBe([
        ToolResultEncoding::UNENCODABLE_MARKER => true,
        'tool' => 'docs.binary',
    ]);
    // Identity fields are untouched, and the whole entry now encodes.
    expect($degraded[0]['id'])->toBe('call-1');
    expect($degraded[0]['name'])->toBe('docs.binary');
    expect($degraded[0]['result_id'])->toBe('result-1');
    expect(json_encode($degraded, JSON_THROW_ON_ERROR))->toBeString();
});

test('SwarmToolCall::toArray degrades unencodable arguments so the strict store encode never throws', function () use ($invalidUtf8) {
    $event = new SwarmToolCall(
        id: 'evt-1',
        runId: 'run-1',
        stepIndex: 0,
        agentClass: 'App\\Agents\\Editor',
        toolCall: new ToolCallData(
            id: 'call-1',
            name: 'docs.binary',
            arguments: ['path' => $invalidUtf8],
            resultId: 'result-1',
        ),
        timestamp: 1_710_000_000,
    );

    $array = $event->toArray();

    expect($array['tool_call']['arguments'])->toBe([
        ToolResultEncoding::UNENCODABLE_ARGUMENTS_MARKER => true,
        'tool' => 'docs.binary',
    ]);
    // The whole event payload now encodes through the strict store unchanged.
    expect(json_encode($array, JSON_THROW_ON_ERROR))->toBeString();
});

test('SwarmToolResult::toArray degrades unencodable arguments alongside the result', function () use ($invalidUtf8) {
    $event = new SwarmToolResult(
        id: 'evt-1',
        runId: 'run-1',
        stepIndex: 0,
        agentClass: 'App\\Agents\\Editor',
        toolResult: new ToolResultData(
            id: 'call-1',
            name: 'docs.binary',
            arguments: ['path' => $invalidUtf8],
            result: 'ok',
            resultId: 'result-1',
        ),
        successful: true,
        error: null,
        timestamp: 1_710_000_000,
    );

    $array = $event->toArray();

    expect($array['tool_result']['arguments'])->toBe([
        ToolResultEncoding::UNENCODABLE_ARGUMENTS_MARKER => true,
        'tool' => 'docs.binary',
    ]);
    // An encodable result still passes through intact.
    expect($array['tool_result']['result'])->toBe('ok');
    expect(json_encode($array, JSON_THROW_ON_ERROR))->toBeString();
});
