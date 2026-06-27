<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalSealBarrier;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalVoidEdge;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeChildrenDecided;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeClosed;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeOpened;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmUnknownEvent;

/**
 * Direct, data-driven serialization coverage for every concrete
 * `Streaming/Events/*` event. These classes are otherwise only exercised
 * end-to-end via the crash-replay feature tests, where a silent field drop
 * (e.g. an error event losing `recoverable` or its `exception_class`) would
 * surface only on a real replay. Here we assert per class that
 * `toArray()` → `SwarmStreamEvent::fromArray()` → `toArray()` is identical
 * and that every field on the original payload survives the round trip.
 *
 * Deserialization goes through the base `SwarmStreamEvent::fromArray()`
 * dispatcher rather than each class's own `fromArray()` because that is the
 * realistic replay path: it routes on `type`, restores `invocation_id` AND the
 * structural `node_id` (#284), and delegates to the concrete class. Round-
 * tripping through it proves the type tag, invocation-id, and node-id plumbing
 * survive alongside the class-specific fields.
 *
 * Every event carries `node_id` (#284). The cases below pair a "full" payload
 * with a non-null `node_id` against a "null/empty" payload with `node_id` null,
 * so each class round-trips the structural tag at both extremes.
 */

/**
 * The concrete events under test. The directory-enumeration guard below
 * asserts this list stays in lock-step with `src/Streaming/Events/*`, so a
 * newly added event class fails the suite until it is given coverage here.
 *
 * @var array<class-string<SwarmStreamEvent>>
 */
const ROUND_TRIP_EVENT_CLASSES = [
    SwarmCausalSealBarrier::class,
    SwarmCausalVoidEdge::class,
    SwarmNodeChildrenDecided::class,
    SwarmNodeClosed::class,
    SwarmNodeOpened::class,
    SwarmReasoningDelta::class,
    SwarmReasoningEnd::class,
    SwarmStepEnd::class,
    SwarmStepStart::class,
    SwarmStreamEnd::class,
    SwarmStreamError::class,
    SwarmStreamStart::class,
    SwarmTextDelta::class,
    SwarmTextEnd::class,
    SwarmToolCall::class,
    SwarmToolResult::class,
    SwarmUnknownEvent::class,
];

/**
 * Representative payloads per event class: a "full" payload exercising every
 * field with a meaningful value, and a "null/empty" payload exercising the
 * nullable/optional fields at their absent extreme. Every payload carries an
 * explicit `invocation_id` and `node_id` so the round trip also covers those
 * base-class fields — `node_id` set on the full case, null on the absent case.
 *
 * Each payload is the canonical `toArray()` shape — what is actually persisted —
 * so re-serializing the rehydrated event must reproduce it byte-for-byte,
 * including key order (`id`, `invocation_id`, `node_id`, `type`, …).
 *
 * @return array<string, array{0: class-string<SwarmStreamEvent>, 1: array<string, mixed>}>
 */
function event_payload_cases(): array
{
    $cases = [];

    // SwarmReasoningDelta — full + null delta/summary.
    $cases['reasoning_delta full'] = [SwarmReasoningDelta::class, [
        'id' => 'evt-rd-1',
        'invocation_id' => 'inv-rd-1',
        'node_id' => 'node-rd-1',
        'type' => 'swarm_reasoning_delta',
        'run_id' => 'run-1',
        'step_index' => 3,
        'agent_class' => 'App\\Agents\\Thinker',
        'reasoning_id' => 'reason-1',
        'delta' => 'because the sky is blue',
        'timestamp' => 1_700_000_000,
        'summary' => ['key' => 'value', 'nested' => ['deep' => true]],
    ]];
    $cases['reasoning_delta null delta and summary'] = [SwarmReasoningDelta::class, [
        'id' => 'evt-rd-2',
        'invocation_id' => 'inv-rd-2',
        'node_id' => null,
        'type' => 'swarm_reasoning_delta',
        'run_id' => 'run-1',
        'step_index' => 0,
        'agent_class' => 'App\\Agents\\Thinker',
        'reasoning_id' => 'reason-2',
        'delta' => null,
        'timestamp' => 1_700_000_001,
        'summary' => null,
    ]];

    // SwarmReasoningEnd — full + null summary.
    $cases['reasoning_end full'] = [SwarmReasoningEnd::class, [
        'id' => 'evt-re-1',
        'invocation_id' => 'inv-re-1',
        'node_id' => 'node-re-1',
        'type' => 'swarm_reasoning_end',
        'run_id' => 'run-1',
        'step_index' => 2,
        'agent_class' => 'App\\Agents\\Thinker',
        'reasoning_id' => 'reason-1',
        'timestamp' => 1_700_000_002,
        'summary' => ['turns' => 4],
    ]];
    $cases['reasoning_end null summary'] = [SwarmReasoningEnd::class, [
        'id' => 'evt-re-2',
        'invocation_id' => 'inv-re-2',
        'node_id' => null,
        'type' => 'swarm_reasoning_end',
        'run_id' => 'run-1',
        'step_index' => 0,
        'agent_class' => 'App\\Agents\\Thinker',
        'reasoning_id' => 'reason-2',
        'timestamp' => 1_700_000_003,
        'summary' => null,
    ]];

    // SwarmStepEnd — full + null output / null duration / empty metadata.
    $cases['step_end full'] = [SwarmStepEnd::class, [
        'id' => 'evt-se-1',
        'invocation_id' => 'inv-se-1',
        'node_id' => 'node-se-1',
        'type' => 'swarm_step_end',
        'run_id' => 'run-1',
        'step_index' => 1,
        'agent_class' => 'App\\Agents\\Writer',
        'agent' => 'writer',
        'output' => 'the finished draft',
        'duration_ms' => 1234,
        'metadata' => ['tokens' => 42, 'cached' => false],
        'timestamp' => 1_700_000_004,
    ]];
    $cases['step_end null output and duration, empty metadata'] = [SwarmStepEnd::class, [
        'id' => 'evt-se-2',
        'invocation_id' => 'inv-se-2',
        'node_id' => null,
        'type' => 'swarm_step_end',
        'run_id' => 'run-1',
        'step_index' => 0,
        'agent_class' => 'App\\Agents\\Writer',
        'agent' => 'writer',
        'output' => null,
        'duration_ms' => null,
        'metadata' => [],
        'timestamp' => 1_700_000_005,
    ]];

    // SwarmStepStart — full + null input.
    $cases['step_start full'] = [SwarmStepStart::class, [
        'id' => 'evt-ss-1',
        'invocation_id' => 'inv-ss-1',
        'node_id' => 'node-ss-1',
        'type' => 'swarm_step_start',
        'run_id' => 'run-1',
        'step_index' => 1,
        'agent_class' => 'App\\Agents\\Writer',
        'agent' => 'writer',
        'input' => 'write the intro',
        'timestamp' => 1_700_000_006,
    ]];
    $cases['step_start null input'] = [SwarmStepStart::class, [
        'id' => 'evt-ss-2',
        'invocation_id' => 'inv-ss-2',
        'node_id' => null,
        'type' => 'swarm_step_start',
        'run_id' => 'run-1',
        'step_index' => 0,
        'agent_class' => 'App\\Agents\\Writer',
        'agent' => 'writer',
        'input' => null,
        'timestamp' => 1_700_000_007,
    ]];

    // SwarmStreamEnd — full + null output / empty usage+metadata.
    $cases['stream_end full'] = [SwarmStreamEnd::class, [
        'id' => 'evt-end-1',
        'invocation_id' => 'inv-end-1',
        'node_id' => 'node-end-1',
        'type' => 'swarm_stream_end',
        'run_id' => 'run-1',
        'output' => 'final answer',
        'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        'metadata' => ['model' => 'demo'],
        'timestamp' => 1_700_000_008,
    ]];
    $cases['stream_end null output, empty usage and metadata'] = [SwarmStreamEnd::class, [
        'id' => 'evt-end-2',
        'invocation_id' => 'inv-end-2',
        'node_id' => null,
        'type' => 'swarm_stream_end',
        'run_id' => 'run-1',
        'output' => null,
        'usage' => [],
        'metadata' => [],
        'timestamp' => 1_700_000_009,
    ]];

    // SwarmStreamError — full (recoverable true) + null message/class + recoverable false.
    $cases['stream_error full recoverable'] = [SwarmStreamError::class, [
        'id' => 'evt-err-1',
        'invocation_id' => 'inv-err-1',
        'node_id' => 'node-err-1',
        'type' => 'swarm_stream_error',
        'run_id' => 'run-1',
        'message' => 'provider timed out',
        'exception_class' => 'RuntimeException',
        'recoverable' => true,
        'metadata' => ['attempt' => 2],
        'timestamp' => 1_700_000_010,
    ]];
    $cases['stream_error null message and class, not recoverable'] = [SwarmStreamError::class, [
        'id' => 'evt-err-2',
        'invocation_id' => 'inv-err-2',
        'node_id' => null,
        'type' => 'swarm_stream_error',
        'run_id' => 'run-1',
        'message' => null,
        'exception_class' => null,
        'recoverable' => false,
        'metadata' => [],
        'timestamp' => 1_700_000_011,
    ]];

    // SwarmStreamStart — full + null input / empty metadata.
    $cases['stream_start full'] = [SwarmStreamStart::class, [
        'id' => 'evt-start-1',
        'invocation_id' => 'inv-start-1',
        'node_id' => 'node-start-1',
        'type' => 'swarm_stream_start',
        'run_id' => 'run-1',
        'swarm_class' => 'App\\Swarms\\Demo',
        'topology' => 'sequential',
        'input' => 'kick off the run',
        'metadata' => ['labels' => ['a', 'b']],
        'timestamp' => 1_700_000_012,
    ]];
    $cases['stream_start null input, empty metadata'] = [SwarmStreamStart::class, [
        'id' => 'evt-start-2',
        'invocation_id' => 'inv-start-2',
        'node_id' => null,
        'type' => 'swarm_stream_start',
        'run_id' => 'run-1',
        'swarm_class' => 'App\\Swarms\\Demo',
        'topology' => 'hierarchical',
        'input' => null,
        'metadata' => [],
        'timestamp' => 1_700_000_013,
    ]];

    // SwarmTextDelta — full + null delta.
    $cases['text_delta full'] = [SwarmTextDelta::class, [
        'id' => 'evt-td-1',
        'invocation_id' => 'inv-td-1',
        'node_id' => 'node-td-1',
        'type' => 'swarm_text_delta',
        'run_id' => 'run-1',
        'step_index' => 1,
        'agent_class' => 'App\\Agents\\Writer',
        'delta' => 'Hello, ',
        'timestamp' => 1_700_000_014,
    ]];
    $cases['text_delta null delta'] = [SwarmTextDelta::class, [
        'id' => 'evt-td-2',
        'invocation_id' => 'inv-td-2',
        'node_id' => null,
        'type' => 'swarm_text_delta',
        'run_id' => 'run-1',
        'step_index' => 0,
        'agent_class' => 'App\\Agents\\Writer',
        'delta' => null,
        'timestamp' => 1_700_000_015,
    ]];

    // SwarmTextEnd — full (node set) + node-null variant (no other nullable fields).
    $cases['text_end full'] = [SwarmTextEnd::class, [
        'id' => 'evt-te-1',
        'invocation_id' => 'inv-te-1',
        'node_id' => 'node-te-1',
        'type' => 'swarm_text_end',
        'run_id' => 'run-1',
        'step_index' => 1,
        'agent_class' => 'App\\Agents\\Writer',
        'message_id' => 'msg-1',
        'timestamp' => 1_700_000_016,
    ]];
    $cases['text_end null node'] = [SwarmTextEnd::class, [
        'id' => 'evt-te-2',
        'invocation_id' => 'inv-te-2',
        'node_id' => null,
        'type' => 'swarm_text_end',
        'run_id' => 'run-1',
        'step_index' => 2,
        'agent_class' => 'App\\Agents\\Writer',
        'message_id' => 'msg-2',
        'timestamp' => 1_700_000_017,
    ]];

    // SwarmToolCall — full nested ToolCall + minimal nested ToolCall.
    $cases['tool_call full'] = [SwarmToolCall::class, [
        'id' => 'evt-tc-1',
        'invocation_id' => 'inv-tc-1',
        'node_id' => 'node-tc-1',
        'type' => 'swarm_tool_call',
        'run_id' => 'run-1',
        'step_index' => 1,
        'agent_class' => 'App\\Agents\\Worker',
        'tool_call' => [
            'id' => 'call-1',
            'name' => 'search',
            'arguments' => ['q' => 'laravel'],
            'result_id' => 'res-1',
            'reasoning_id' => 'reason-1',
            'reasoning_summary' => ['step' => 'plan'],
        ],
        'timestamp' => 1_700_000_018,
    ]];
    $cases['tool_call minimal nested nulls'] = [SwarmToolCall::class, [
        'id' => 'evt-tc-2',
        'invocation_id' => 'inv-tc-2',
        'node_id' => null,
        'type' => 'swarm_tool_call',
        'run_id' => 'run-1',
        'step_index' => 0,
        'agent_class' => 'App\\Agents\\Worker',
        'tool_call' => [
            'id' => 'call-2',
            'name' => 'noop',
            'arguments' => [],
            'result_id' => null,
            'reasoning_id' => null,
            'reasoning_summary' => null,
        ],
        'timestamp' => 1_700_000_019,
    ]];

    // SwarmToolResult — successful with scalar result + failed with null error/result.
    $cases['tool_result successful'] = [SwarmToolResult::class, [
        'id' => 'evt-tr-1',
        'invocation_id' => 'inv-tr-1',
        'node_id' => 'node-tr-1',
        'type' => 'swarm_tool_result',
        'run_id' => 'run-1',
        'step_index' => 1,
        'agent_class' => 'App\\Agents\\Worker',
        'tool_result' => [
            'id' => 'call-1',
            'name' => 'search',
            'arguments' => ['q' => 'laravel'],
            'result' => ['hits' => 3],
            'result_id' => 'res-1',
        ],
        'successful' => true,
        'error' => null,
        'timestamp' => 1_700_000_020,
    ]];
    $cases['tool_result failed null result and error'] = [SwarmToolResult::class, [
        'id' => 'evt-tr-2',
        'invocation_id' => 'inv-tr-2',
        'node_id' => null,
        'type' => 'swarm_tool_result',
        'run_id' => 'run-1',
        'step_index' => 0,
        'agent_class' => 'App\\Agents\\Worker',
        'tool_result' => [
            'id' => 'call-2',
            'name' => 'noop',
            'arguments' => [],
            'result' => null,
            'result_id' => null,
        ],
        'successful' => false,
        'error' => 'tool exploded',
        'timestamp' => 1_700_000_021,
    ]];

    // SwarmCausalSealBarrier — infrastructure compaction marker; no nullable fields.
    $cases['causal_seal_barrier with node_id'] = [SwarmCausalSealBarrier::class, [
        'id' => 'evt-csb-1',
        'invocation_id' => 'inv-csb-1',
        'node_id' => 'node-csb-1',
        'type' => 'swarm_causal_seal_barrier',
        'run_id' => 'run-1',
        'timestamp' => 1_700_000_020,
    ]];
    $cases['causal_seal_barrier null node_id'] = [SwarmCausalSealBarrier::class, [
        'id' => 'evt-csb-2',
        'invocation_id' => 'inv-csb-2',
        'node_id' => null,
        'type' => 'swarm_causal_seal_barrier',
        'run_id' => 'run-1',
        'timestamp' => 1_700_000_021,
    ]];

    // SwarmCausalVoidEdge — two void types; no nullable fields (run-scoped edge).
    $cases['causal_void_edge supersedes'] = [SwarmCausalVoidEdge::class, [
        'id' => 'evt-cve-1',
        'invocation_id' => 'inv-cve-1',
        'node_id' => 'node-cve-1',
        'type' => 'swarm_causal_void_edge',
        'run_id' => 'run-1',
        'void_type' => 'supersedes',
        'target_event_id' => 'evt-target-1',
        'reason' => 'coordinator re-routed',
        'timestamp' => 1_700_000_022,
    ]];
    $cases['causal_void_edge replaces'] = [SwarmCausalVoidEdge::class, [
        'id' => 'evt-cve-2',
        'invocation_id' => 'inv-cve-2',
        'node_id' => null,
        'type' => 'swarm_causal_void_edge',
        'run_id' => 'run-1',
        'void_type' => 'replaces',
        'target_event_id' => 'evt-target-2',
        'reason' => 'crash-retry of the same step',
        'timestamp' => 1_700_000_023,
    ]];

    // SwarmNodeOpened (#284) — root node (parent null) self-identifying +
    // a child node with a parent and a captured rationale.
    $cases['node_opened root'] = [SwarmNodeOpened::class, [
        'id' => 'node-no-1',
        'invocation_id' => 'inv-no-1',
        'node_id' => 'node-no-1',
        'type' => 'swarm_node_opened',
        'run_id' => 'run-1',
        'parent_node_id' => null,
        'role' => 'coordinator',
        'rationale' => null,
        'timestamp' => 1_700_000_024,
    ]];
    $cases['node_opened child with rationale'] = [SwarmNodeOpened::class, [
        'id' => 'node-no-2',
        'invocation_id' => 'inv-no-2',
        'node_id' => 'node-no-2',
        'type' => 'swarm_node_opened',
        'run_id' => 'run-1',
        'parent_node_id' => 'node-no-1',
        'role' => 'worker',
        'rationale' => 'delegated the drafting subtask',
        'timestamp' => 1_700_000_025,
    ]];
    // A degenerate null-node payload — the convention is node_id == id, but the
    // base carry must still round-trip a null tag safely on a structural event.
    $cases['node_opened null node tag'] = [SwarmNodeOpened::class, [
        'id' => 'node-no-3',
        'invocation_id' => 'inv-no-3',
        'node_id' => null,
        'type' => 'swarm_node_opened',
        'run_id' => 'run-1',
        'parent_node_id' => null,
        'role' => 'worker',
        'rationale' => null,
        'timestamp' => 1_700_000_030,
    ]];

    // SwarmNodeChildrenDecided (#284) — ordered children + a single-child
    // decision with a null rationale.
    $cases['node_children_decided ordered'] = [SwarmNodeChildrenDecided::class, [
        'id' => 'evt-ncd-1',
        'invocation_id' => 'inv-ncd-1',
        'node_id' => 'node-no-1',
        'type' => 'swarm_node_children_decided',
        'run_id' => 'run-1',
        'child_node_ids' => ['node-a', 'node-b', 'node-c'],
        'rationale' => 'three independent subtasks',
        'timestamp' => 1_700_000_026,
    ]];
    $cases['node_children_decided single null rationale'] = [SwarmNodeChildrenDecided::class, [
        'id' => 'evt-ncd-2',
        'invocation_id' => 'inv-ncd-2',
        'node_id' => null,
        'type' => 'swarm_node_children_decided',
        'run_id' => 'run-1',
        'child_node_ids' => ['node-only'],
        'rationale' => null,
        'timestamp' => 1_700_000_027,
    ]];

    // SwarmNodeClosed (#284) — closed with a result + closed with no payload.
    $cases['node_closed with result'] = [SwarmNodeClosed::class, [
        'id' => 'evt-nc-1',
        'invocation_id' => 'inv-nc-1',
        'node_id' => 'node-no-1',
        'type' => 'swarm_node_closed',
        'run_id' => 'run-1',
        'result' => 'the synthesized answer',
        'timestamp' => 1_700_000_028,
    ]];
    $cases['node_closed null result'] = [SwarmNodeClosed::class, [
        'id' => 'evt-nc-2',
        'invocation_id' => 'inv-nc-2',
        'node_id' => null,
        'type' => 'swarm_node_closed',
        'run_id' => 'run-1',
        'result' => null,
        'timestamp' => 1_700_000_029,
    ]];

    // SwarmUnknownEvent — sentinel for types not in this version's registry.
    // The payload uses an imaginary future type; fromArray() hits the default arm
    // and returns a SwarmUnknownEvent that carries the raw payload unchanged.
    $cases['unknown_event with node_id'] = [SwarmUnknownEvent::class, [
        'id' => 'evt-unk-1',
        'invocation_id' => 'inv-unk-1',
        'node_id' => 'node-unk-1',
        'type' => 'swarm_future_event_type',
        'run_id' => 'run-1',
        'timestamp' => 1_700_000_050,
    ]];
    $cases['unknown_event null node_id'] = [SwarmUnknownEvent::class, [
        'id' => 'evt-unk-2',
        'invocation_id' => 'inv-unk-2',
        'node_id' => null,
        'type' => 'swarm_future_event_type',
        'run_id' => 'run-1',
        'timestamp' => 1_700_000_051,
    ]];

    return $cases;
}

dataset('event_payloads', fn (): array => event_payload_cases());

test('event payload round-trips identically and preserves every field', function (string $expectedClass, array $payload): void {
    $event = SwarmStreamEvent::fromArray($payload);

    expect($event)->toBeInstanceOf($expectedClass);

    $roundTripped = $event->toArray();

    // toArray() → fromArray() → toArray() reproduces the canonical shape exactly.
    expect($roundTripped)->toBe($payload);

    // And re-hydrating once more is stable (idempotent past the first pass).
    expect(SwarmStreamEvent::fromArray($roundTripped)->toArray())->toBe($payload);

    // Every individual field on the original payload survives by key and value,
    // so a silently dropped field would fail here even if toArray() happened to
    // reorder or omit it.
    foreach ($payload as $key => $value) {
        expect($roundTripped)->toHaveKey($key);
        expect($roundTripped[$key])->toEqual($value);
    }

    // The restored invocation id is carried on the base class, not just echoed
    // inside the array — the realistic replay path must rehydrate it.
    expect($event->invocationId)->toBe($payload['invocation_id']);

    // The structural node tag (#284) rehydrates onto the base class too, at both
    // extremes: a set node_id is restored, a null one stays null.
    expect($event->nodeId)->toBe($payload['node_id']);
})->with('event_payloads');

test('every concrete event class has at least one round-trip payload case', function (): void {
    $covered = [];

    foreach (event_payload_cases() as $case) {
        $covered[$case[0]] = true;
    }

    foreach (ROUND_TRIP_EVENT_CLASSES as $class) {
        expect($covered)->toHaveKey($class, "Event class [{$class}] has no round-trip payload case.");
    }
});

test('every concrete event class round-trips both a set and a null node_id', function (): void {
    $seenSet = [];
    $seenNull = [];

    foreach (event_payload_cases() as $case) {
        [$class, $payload] = $case;

        if (is_string($payload['node_id'] ?? null)) {
            $seenSet[$class] = true;
        }

        if (array_key_exists('node_id', $payload) && $payload['node_id'] === null) {
            $seenNull[$class] = true;
        }
    }

    foreach (ROUND_TRIP_EVENT_CLASSES as $class) {
        expect($seenSet)->toHaveKey($class, "Event class [{$class}] has no payload case with a non-null node_id.");
        expect($seenNull)->toHaveKey($class, "Event class [{$class}] has no payload case with a null node_id.");
    }
});

test('round-trip coverage list stays in lock-step with the events directory', function (): void {
    $directory = dirname(__DIR__, 3).'/src/Streaming/Events';

    $discovered = collect(glob($directory.'/*.php'))
        ->map(fn (string $path): string => 'BuiltByBerry\\LaravelSwarm\\Streaming\\Events\\'.basename($path, '.php'))
        // Keep only concrete serializable leaf events. This drops the abstract
        // SwarmStreamEvent base/dispatcher AND any supporting types that live
        // beside the events (e.g. the CausalVoidEdgeType enum) — neither is a
        // serializable event, while every concrete SwarmStreamEvent subclass
        // still must carry round-trip coverage.
        ->filter(fn (string $class): bool => is_subclass_of($class, SwarmStreamEvent::class))
        ->sort()
        ->values()
        ->all();

    $covered = collect(ROUND_TRIP_EVENT_CLASSES)->sort()->values()->all();

    // A new src/Streaming/Events/*.php class added without a coverage entry
    // (or a stale entry removed from disk) fails the suite here.
    expect($covered)->toBe(
        $discovered,
        'Every src/Streaming/Events/* class (except the abstract SwarmStreamEvent base) must appear in ROUND_TRIP_EVENT_CLASSES with a round-trip payload case.',
    );
});
