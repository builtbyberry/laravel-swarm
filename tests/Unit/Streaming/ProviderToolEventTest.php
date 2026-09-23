<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Responses\ProviderToolData;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\ProviderToolEventMapper;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingAuditCapturePolicy;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;

function providerNative(array $data = []): ProviderToolEvent
{
    return (new ProviderToolEvent('native-id', 'item-id', 'future.call', $data, 'denied', 123, 'vendor'))->withInvocationId('native-invocation');
}

it('maps exact native provider identity and status separately from the Swarm discriminator', function () {
    $native = providerNative(['a' => [false, null, 0, 1.5, 'Ω']]);
    expect($native->toArray())->not->toHaveKey('invocation_id');
    $bytes = 0;
    $event = app(ProviderToolEventMapper::class)->map($native, RunContext::from('task', 'run'), 3, 'Agent', $bytes);
    $event->withNodeId('node')->withAttemptEpoch(4);
    $wire = $event->toArray();
    expect($wire)->toMatchArray(['id' => 'native-id', 'type' => 'swarm_provider_tool_event',
        'run_id' => 'run', 'step_index' => 3, 'agent_class' => 'Agent', 'invocation_id' => 'native-invocation',
        'item_id' => 'item-id', 'provider_type' => 'future.call', 'provider_status' => 'denied',
        'provider' => 'vendor', 'timestamp' => 123, 'node_id' => 'node', 'attempt_epoch' => 4,
        'data_status' => 'available', 'data_reasons' => [], 'data' => $native->data]);
    expect(SwarmStreamEvent::fromArray(json_decode(json_encode($wire), true))->toArray())->toBe($wire)
        ->and($event->toStreamedEvent()->data)->toBe($wire);
    $native->invocationId = null;
    $null = app(ProviderToolEventMapper::class)->map($native, RunContext::from('task'), 0, 'Agent', $bytes);
    expect($null->invocationId)->toBeNull()->and($null->nodeId)->toBeNull()->and($null->attemptEpoch)->toBeNull();
});

it('withholds arbitrary keys before traversing protected data', function (CaptureDecision $decision, string $status) {
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $decision));
    $hook = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new RuntimeException('secret hook must not run');
        }
    };
    $recursive = ['secret-key' => $hook];
    $recursive['recursive'] = &$recursive;
    $bytes = 0;
    $event = app(ProviderToolEventMapper::class)->map(providerNative($recursive), RunContext::from('task'), 0, 'Agent', $bytes);
    expect($event->payload->status)->toBe($status)->and($bytes)->toBe(0)
        ->and(json_encode($event->toArray()))->not->toContain('secret', 'recursive');
    expect(array_key_exists('data', $event->toArray()))->toBe($decision !== CaptureDecision::Skip);
})->with([[CaptureDecision::Redact, 'redacted'], [CaptureDecision::Skip, 'omitted']]);

it('bounds unsafe structured data without running object hooks or leaking errors', function () {
    $recursive = [];
    $recursive['self'] = &$recursive;
    $hook = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new RuntimeException('secret hook');
        }
    };
    foreach ([[['x' => $hook], 'unsupported_type'], [['x' => INF], 'malformed'], [["\xB1" => 'x'], 'malformed'], [['x' => "\xB1"], 'malformed'], [$recursive, 'limit']] as [$data, $reason]) {
        $result = ProviderToolData::capture($data);
        expect($result->data)->toBe([])->and($result->reasons)->toBe([$reason])
            ->and($result->status)->toBe($reason === 'limit' ? 'partial' : 'unavailable');
    }
    $resource = fopen('php://memory', 'r');
    expect(ProviderToolData::capture(['resource' => $resource])->reasons)->toBe(['unsupported_type']);
    fclose($resource);
    expect(ProviderToolData::capture([])->status)->toBe('available')
        ->and(ProviderToolData::fromArray([])->status)->toBe('unknown')
        ->and(ProviderToolData::fromArray(['data_status' => 'available', 'data' => []])->status)->toBe('unavailable');
});

it('enforces exact byte limits and local cumulative budgets with explicit truncation', function () {
    $data = ['quote' => '"Ω'];
    $length = strlen(json_encode($data));
    expect(ProviderToolData::capture($data, $length)->data)->toBe($data)
        ->and(ProviderToolData::capture($data, $length - 1)->reasons)->toBe(['limit']);
    config()->set('swarm.provider_tools.max_event_bytes', $length);
    config()->set('swarm.provider_tools.max_step_bytes', $length);
    $bytes = 0;
    $other = 0;
    $mapper = app(ProviderToolEventMapper::class);
    $context = RunContext::from('task');
    expect($mapper->map(providerNative($data), $context, 0, 'A', $bytes)->payload->status)->toBe('available')
        ->and($mapper->map(providerNative($data), $context, 0, 'A', $bytes)->payload->reasons)->toBe(['limit'])
        ->and($mapper->map(providerNative($data), $context, 1, 'B', $other)->payload->status)->toBe('available');
    config()->set('swarm.provider_tools.max_event_bytes', -1);
    $zero = 0;
    expect($mapper->map(providerNative([]), $context, 0, 'A', $zero)->payload->reasons)->toBe(['limit']);
    config()->set('swarm.provider_tools.max_event_bytes', 'invalid');
    config()->set('swarm.provider_tools.max_step_bytes', PHP_INT_MAX);
    expect($mapper->map(providerNative([]), $context, 0, 'A', $zero)->payload->status)->toBe('available');
    config()->set('swarm.provider_tools.max_depth', 0);
    expect($mapper->map(providerNative(['a' => ['b']]), $context, 0, 'A', $zero)->payload->reasons)->toBe(['limit']);
});

it('derives causal identity from stamped scope without trusting serialized hashes', function () {
    $event = new SwarmProviderToolEvent('same-id', 'run', 0, 'Agent', 'same-item', 'call', 'failed', null, 1, ProviderToolData::capture([]));
    $initial = $event->causalId();
    $event->withNodeId('node')->withAttemptEpoch(0)->withInvocationId('invocation');
    $old = $event->causalId();
    $event->withAttemptEpoch(1);
    expect($old)->not->toBe($initial)->not->toBe($event->causalId());
    $wire = $event->toArray();
    $wire['causal_id'] = 'forged';
    expect(SwarmStreamEvent::fromArray($wire)->toArray()['causal_id'])->toBe($event->causalId());
});
