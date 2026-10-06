<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\BooleanCapturePolicy;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StoresDurableCitationEvidence;
use BuiltByBerry\LaravelSwarm\Contracts\StoresDurableNativeStepResults;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseStreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\NativeStepResultCodec;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Support\NativeStepResultProjector;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\NativeResultAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingAuditCapturePolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent as LaravelAgent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\StructuredStep;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StructuredAgentResponse;

final class RichNativeAgent implements LaravelAgent
{
    use Promptable;

    public static StructuredAgentResponse $response;

    public function instructions(): string
    {
        return 'Return the configured rich response.';
    }

    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        return clone self::$response;
    }
}

final class RichNativeSequentialSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new RichNativeAgent];
    }
}

final class RichNativeTwoStepSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new RichNativeAgent, new RichNativeAgent];
    }
}

function richNativeStepResponse(string $invocation = 'native-invocation'): StructuredAgentResponse
{
    $structured = [
        'start_at' => 'finish',
        'nodes' => ['finish' => ['type' => 'finish', 'output' => 'done']],
    ];
    $call = new ToolCall('tool-call-1', 'lookup', ['secret' => 'argument-secret'], 'tool-result-lookup');
    $result = new ToolResult('tool-result-lookup', 'lookup', ['secret' => 'argument-secret'], ['secret' => 'result-secret'], 'tool-result-native');
    $meta = new Meta('fake-provider', 'fake-model', collect([
        new UrlCitation('https://example.test/source', 'source title'),
    ]));

    return (new StructuredAgentResponse(
        $invocation,
        $structured,
        json_encode($structured, JSON_THROW_ON_ERROR),
        new TextUsage(11, 7, null, 2, 3),
        $meta,
    ))
        ->withReasoning('reasoning-secret')
        ->withinConversation('conversation-row')
        ->withStoredMessages('user-message-row', 'assistant-message-row')
        ->withToolCallsAndResults(collect([$call]), collect([$result]))
        ->withSteps(collect([new StructuredStep(
            text: 'generation-secret',
            structured: ['step' => 'typed'],
            toolCalls: [$call],
            toolResults: [$result],
            finishReason: FinishReason::Stop,
            usage: new TextUsage(5, 3, null, null, 1),
            meta: new Meta('step-provider', 'step-model'),
            reasoning: 'step-reasoning-secret',
            replayBlocks: [['must' => 'not persist']],
        )]));
}

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.capture.inputs', true);
    config()->set('swarm.capture.outputs', true);
    config()->set('swarm.capture.artifacts', true);
    config()->set('swarm.persistence.encrypt_at_rest', false);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

it('matches direct native fields through a live Swarm step without duplicating final evidence', function () {
    $direct = richNativeStepResponse();
    RichNativeAgent::$response = $direct;

    $swarm = app(SwarmRunner::class)->agent(new RichNativeAgent)->prompt('compare native');
    $native = $swarm->steps[0]->nativeResult;

    expect($native)->not->toBeNull()
        ->and($swarm->output)->toBe((string) $direct)
        ->and($native->structured)->toBe($direct->structured)
        ->and($native->reasoning)->toBe($direct->reasoning)
        ->and($native->provider)->toBe($direct->meta->provider)
        ->and($native->model)->toBe($direct->meta->model)
        ->and($native->invocationId)->toBe($direct->invocationId)
        ->and($native->conversationId)->toBe($direct->conversationId)
        ->and($native->userMessageId)->toBe($direct->userMessageId)
        ->and($native->assistantMessageId)->toBe($direct->assistantMessageId)
        ->and($native->generationSteps[0]['structured'])->toBe(['step' => 'typed'])
        ->and($native->generationSteps[0]['usage'])->toBe($direct->steps[0]->usage->toArray())
        ->and($native->tools[0])->toBe([
            'call_id' => 'tool-call-1', 'result_id' => 'tool-result-native', 'name' => 'lookup', 'status' => 'succeeded',
        ])
        ->and($native->toArray())->not->toHaveKeys(['usage', 'citations', 'raw_response', 'messages', 'replay_blocks', 'provider_tool_calls', 'pending_approvals'])
        ->and($swarm->usage)->toBe($direct->usage->toArray())
        ->and($swarm->citationEvidence->items)->toHaveCount(1);
});

it('matches the direct native projection through sync queue and durable boundaries', function (string $mode) {
    $direct = richNativeStepResponse('cross-mode-invocation');
    RichNativeAgent::$response = $direct;
    $expected = app(NativeStepResultProjector::class)->fromResponse($direct)->toArray();

    if ($mode === 'sync') {
        $actual = RichNativeSequentialSwarm::make()->prompt('compare')->steps[0]->nativeResult?->toArray();
    } elseif ($mode === 'queue') {
        $context = RunContext::from('compare');
        (new InvokeSwarm(RichNativeSequentialSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));
        $actual = app(RunHistoryStore::class)->find($context->runId)['steps'][0]['native_result'];
    } else {
        config()->set('queue.default', 'null');
        $runId = RichNativeSequentialSwarm::make()->dispatchDurable('compare')->runId;
        (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
        $actual = app(RunHistoryStore::class)->find($runId)['steps'][0]['native_result'];
    }

    expect($actual)->toBe($expected)
        ->and($actual['structured'])->toBe($direct->structured)
        ->and($actual['reasoning'])->toBe($direct->reasoning)
        ->and($actual['conversation_id'])->toBe($direct->conversationId)
        ->and($actual['generation_steps'][0]['structured'])->toBe(['step' => 'typed']);
})->with(['sync', 'queue', 'durable']);

it('applies Full Redact and Skip independently from live access', function (CaptureDecision $decision) {
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Full,
        outputs: $decision,
        artifacts: CaptureDecision::Full,
        activeContext: CaptureDecision::Full,
    ));
    app()->forgetInstance(SwarmCapture::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(RunHistoryStore::class);
    Event::fake([SwarmStepCompleted::class]);

    RichNativeAgent::$response = richNativeStepResponse();
    $response = app(SwarmRunner::class)->agent(new RichNativeAgent)->prompt('capture native');
    $history = app(RunHistoryStore::class)->find($response->metadata['run_id']);
    $persisted = $history['steps'][0];
    Event::assertDispatched(SwarmStepCompleted::class, fn (SwarmStepCompleted $event): bool => $event->nativeResult?->status === match ($decision) {
        CaptureDecision::Full => NativeStepResult::AVAILABLE,
        CaptureDecision::Redact => NativeStepResult::REDACTED,
        CaptureDecision::Skip => NativeStepResult::OMITTED,
    });

    expect($response->steps[0]->nativeResult->structured)->toBe([
        'start_at' => 'finish', 'nodes' => ['finish' => ['type' => 'finish', 'output' => 'done']],
    ])
        ->and($persisted['native_result_status'])->toBe(match ($decision) {
            CaptureDecision::Full => NativeStepResult::AVAILABLE,
            CaptureDecision::Redact => NativeStepResult::REDACTED,
            CaptureDecision::Skip => NativeStepResult::OMITTED,
        });

    if ($decision === CaptureDecision::Full) {
        expect($persisted['native_result']['conversation_id'])->toBe('conversation-row')
            ->and($persisted['native_result']['structured']['start_at'])->toBe('finish');
    } elseif ($decision === CaptureDecision::Redact) {
        expect($persisted['native_result'])
            ->toHaveKeys(['provider', 'model', 'invocation_id', 'generation_steps', 'tools'])
            ->not->toHaveKeys(['structured', 'reasoning', 'conversation_id', 'user_message_id', 'assistant_message_id'])
            ->and(json_encode($persisted['native_result']))->not->toContain('secret', 'start_at');
    } else {
        expect(DB::table('swarm_run_steps')->value('native_result'))->toBeNull();
    }
})->with(CaptureDecision::cases());

it('treats the shipped false output-capture flag as Redact rather than Skip', function () {
    config()->set('swarm.capture.outputs', false);
    app()->instance(CapturePolicy::class, new BooleanCapturePolicy(config()));
    app()->forgetInstance(SwarmCapture::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(RunHistoryStore::class);
    RichNativeAgent::$response = richNativeStepResponse();

    $response = RichNativeSequentialSwarm::make()->prompt('boolean capture');
    $persisted = app(RunHistoryStore::class)->find($response->metadata['run_id'])['steps'][0];

    expect($persisted['native_result_status'])->toBe(NativeStepResult::REDACTED)
        ->and($persisted['native_result'])->not->toHaveKeys(['structured', 'reasoning', 'conversation_id', 'user_message_id', 'assistant_message_id'])
        ->and(json_encode($persisted['native_result'], JSON_THROW_ON_ERROR))->not->toContain('secret', 'conversation-row');
});

it('keeps Redact projections inside the configured total bound', function () {
    config()->set('swarm.native_results.max_bytes', 256);
    $result = new NativeStepResult(
        provider: str_repeat('provider-', 64),
        model: str_repeat('model-', 64),
        invocationId: str_repeat('invocation-', 64),
        tools: [['call_id' => str_repeat('call-', 64), 'status' => 'succeeded']],
    );

    $redacted = app(NativeStepResultProjector::class)->capture($result, CaptureDecision::Redact);

    expect(strlen(json_encode($redacted, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(256)
        ->and($redacted->status)->toBe(NativeStepResult::REDACTED)
        ->and($redacted->reasons)->toContain('capture_redact', 'limit');
});

it('keeps singleton projector invocation state local', function () {
    $properties = collect((new ReflectionClass(NativeStepResultProjector::class))->getProperties())
        ->map->getName()
        ->all();

    expect($properties)->toBe(['config']);
});

it('requires durable native-result stores to compose citation evidence atomically', function () {
    expect(is_subclass_of(StoresDurableNativeStepResults::class, StoresDurableCitationEvidence::class))->toBeTrue();
});

it('bounds structurally and never serializes unrestricted native objects', function () {
    config()->set('swarm.native_results.max_generation_steps', 1);
    config()->set('swarm.native_results.max_tool_statuses', 1);
    config()->set('swarm.native_results.max_structured_items', 1);
    config()->set('swarm.native_results.max_reasoning_bytes', 8);
    config()->set('swarm.native_results.max_bytes', 700);

    $projected = app(NativeStepResultProjector::class)->fromResponse(richNativeStepResponse());
    $json = json_encode($projected, JSON_THROW_ON_ERROR);

    expect($projected->status)->toBe(NativeStepResult::PARTIAL)
        ->and($projected->reasons)->toContain('limit')
        ->and(strlen($json))->toBeLessThanOrEqual(700)
        ->and($json)->not->toContain('argument-secret', 'result-secret', 'must', 'pendingApprovals');
});

it('keeps the total bound after dropping oversized native identities without truncating them', function () {
    config()->set('swarm.native_results.max_bytes', 256);
    $oversized = str_repeat('identity-', 512);
    $response = richNativeStepResponse($oversized)
        ->withinConversation($oversized)
        ->withStoredMessages($oversized, $oversized);

    $projected = app(NativeStepResultProjector::class)->fromResponse($response);
    $json = json_encode($projected, JSON_THROW_ON_ERROR);

    expect(strlen($json))->toBeLessThanOrEqual(256)
        ->and($projected->status)->toBe(NativeStepResult::PARTIAL)
        ->and($projected->reasons)->toContain('limit')
        ->and($projected->invocationId)->toBeNull()
        ->and($projected->conversationId)->toBeNull()
        ->and($projected->userMessageId)->toBeNull()
        ->and($projected->assistantMessageId)->toBeNull()
        ->and($json)->not->toContain(substr($oversized, 0, 32));
});

it('pairs repeated native tool call ids without overwriting a result occurrence', function () {
    $firstCall = new ToolCall('repeated-call', 'lookup', [], 'repeated-lookup');
    $secondCall = new ToolCall('repeated-call', 'lookup', [], 'repeated-lookup');
    $firstResult = new ToolResult('repeated-lookup', 'lookup', [], null, 'first-result', denied: true);
    $secondResult = new ToolResult('repeated-lookup', 'lookup', [], null, 'second-result', failed: true);
    $response = richNativeStepResponse()->withToolCallsAndResults(
        collect([$firstCall, $secondCall]),
        collect([$firstResult, $secondResult]),
    );

    expect(app(NativeStepResultProjector::class)->fromResponse($response)->tools)->toBe([
        ['call_id' => 'repeated-call', 'result_id' => 'first-result', 'name' => 'lookup', 'status' => 'denied'],
        ['call_id' => 'repeated-call', 'result_id' => 'second-result', 'name' => 'lookup', 'status' => 'failed'],
    ]);
});

it('uses native result lookup ids when tool call and result ids differ', function () {
    $call = new ToolCall('call-id', 'lookup', [], 'lookup-id');
    $result = new ToolResult('lookup-id', 'lookup', [], null, 'provider-result-id', denied: true);
    $response = richNativeStepResponse()->withToolCallsAndResults(collect([$call]), collect([$result]));

    expect(app(NativeStepResultProjector::class)->fromResponse($response)->tools)->toBe([
        ['call_id' => 'call-id', 'result_id' => 'provider-result-id', 'name' => 'lookup', 'status' => 'denied'],
    ]);
});

it('seals the whole projection and degrades malformed unsupported and undecryptable payloads', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $codec = new NativeStepResultCodec(app(SwarmPersistenceCipher::class), config());
    $result = app(NativeStepResultProjector::class)->fromResponse(richNativeStepResponse());
    $sealed = $codec->encode($result);

    expect($sealed)->toStartWith('sw0:')
        ->and($sealed)->not->toContain('reasoning-secret', 'conversation-row')
        ->and($codec->decode($sealed)->toArray())->toBe($result->toArray())
        ->and($codec->decode('{bad json')->reasons)->toBe(['malformed'])
        ->and($codec->decode(json_encode(['format_version' => 999, 'status' => 'available']))->reasons)->toBe(['unsupported_version'])
        ->and($codec->decode('sw0:not-ciphertext')->reasons)->toBe(['decrypt_failed']);
});

it('reapplies configured bounds while writing and reading persisted envelopes', function () {
    config()->set('swarm.native_results.max_bytes', 256);
    config()->set('swarm.native_results.max_structured_depth', 2);
    $codec = new NativeStepResultCodec(app(SwarmPersistenceCipher::class), config());

    $oversized = new NativeStepResult(structured: ['secret' => str_repeat('x', 4096)]);
    $stored = $codec->encode($oversized);
    $deep = json_encode([
        'format_version' => 1,
        'status' => 'available',
        'structured' => ['one' => ['two' => ['three' => 'secret']]],
    ], JSON_THROW_ON_ERROR);
    $unrestricted = json_encode([
        'format_version' => 1,
        'status' => 'available',
        'tools' => [['call_id' => 'call', 'status' => 'succeeded', 'raw_provider_payload' => ['secret' => true]]],
    ], JSON_THROW_ON_ERROR);
    $unrestrictedUsage = json_encode([
        'format_version' => 1,
        'status' => 'available',
        'generation_steps' => [['usage' => ['raw' => ['raw_provider_payload' => 'secret']]]],
    ], JSON_THROW_ON_ERROR);
    expect($codec->decode($stored)->toArray())->toBe(NativeStepResult::unavailable(['limit'])->toArray())
        ->and($codec->decode($deep)->toArray())->toBe(NativeStepResult::unavailable(['limit'])->toArray())
        ->and($codec->decode($unrestricted)->toArray())->toBe(NativeStepResult::unavailable(['malformed'])->toArray())
        ->and($codec->decode($unrestrictedUsage)->toArray())->toBe(NativeStepResult::unavailable(['malformed'])->toArray());
});

it('rejects non-scalar generation and tool fields in persisted envelopes', function () {
    $codec = new NativeStepResultCodec(app(SwarmPersistenceCipher::class), config());
    $unrestrictedScalars = new NativeStepResult(
        generationSteps: [[
            'text' => (object) ['raw_provider_payload' => 'generation-text-secret'],
            'provider' => (object) ['raw_provider_payload' => 'generation-provider-secret'],
        ]],
        tools: [[
            'name' => (object) ['raw_provider_payload' => 'tool-name-secret'],
            'status' => (object) ['raw_provider_payload' => 'tool-status-secret'],
        ]],
    );
    $undocumentedToolStatus = json_encode([
        'format_version' => 1,
        'status' => 'available',
        'tools' => [['call_id' => 'call', 'status' => 'provider-private-status']],
    ], JSON_THROW_ON_ERROR);

    expect($codec->decode($codec->encode($unrestrictedScalars))->toArray())->toBe(NativeStepResult::unavailable(['malformed'])->toArray())
        ->and($codec->decode($undocumentedToolStatus)->toArray())->toBe(NativeStepResult::unavailable(['malformed'])->toArray());
});

it('caps native results carried by broadcastable stream events', function () {
    config()->set('swarm.native_results.max_event_bytes', 1024);
    $native = new NativeStepResult(
        structured: ['content' => str_repeat('x', 12000)],
        reasoning: str_repeat('r', 12000),
        provider: 'provider',
        model: 'model',
    );
    $captured = app(SwarmCapture::class)->nativeResultForStreamEvent($native);
    $event = new SwarmStepEnd('event', 'run', 0, RichNativeAgent::class, 'agent', 'done', 1, [], 1, nativeResult: $captured);

    expect(strlen(json_encode($event->toArray(), JSON_THROW_ON_ERROR)))->toBeLessThan(10000)
        ->and($captured?->status)->toBe(NativeStepResult::PARTIAL)
        ->and($captured?->reasons)->toContain('transport_limit');
});

it('preserves omitted cache steps across repeated reads and later writes', function () {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.history.driver', 'cache');
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Full,
        outputs: CaptureDecision::Skip,
        artifacts: CaptureDecision::Full,
        activeContext: CaptureDecision::Full,
    ));
    app()->forgetInstance(SwarmCapture::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(RunHistoryStore::class);
    RichNativeAgent::$response = richNativeStepResponse();

    $response = RichNativeTwoStepSwarm::make()->prompt('cache skip');
    $store = app(RunHistoryStore::class);
    $first = $store->find($response->metadata['run_id']);
    $second = $store->find($response->metadata['run_id']);

    expect($first['steps'])->toHaveCount(2)
        ->and($first['steps'][0]['native_result_status'])->toBe(NativeStepResult::OMITTED)
        ->and($first['steps'][1]['native_result_status'])->toBe(NativeStepResult::OMITTED)
        ->and($second['steps'])->toBe($first['steps']);
});

it('reapplies native-result bounds to cache-backed persisted reads', function () {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.history.driver', 'cache');
    config()->set('swarm.native_results.max_bytes', 256);
    app()->forgetInstance(SwarmCapture::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(RunHistoryStore::class);
    RichNativeAgent::$response = richNativeStepResponse();
    $response = RichNativeSequentialSwarm::make()->prompt('cache bound');
    $key = (string) config('swarm.history.prefix', 'swarm:history:').$response->metadata['run_id'];
    $cache = Cache::store(config('swarm.history.store'));
    $raw = $cache->get($key);
    $raw['steps'][0]['native_result'] = [
        'format_version' => 1,
        'status' => 'available',
        'structured' => ['secret' => str_repeat('x', 4096)],
        'tools' => [['call_id' => 'call', 'status' => 'succeeded', 'raw_provider_payload' => str_repeat('p', 4096)]],
    ];
    $cache->put($key, $raw, 3600);

    $native = app(RunHistoryStore::class)->find($response->metadata['run_id'])['steps'][0]['native_result'];

    expect(strlen(json_encode($native, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(256)
        ->and($native)->not->toHaveKey('raw_provider_payload')
        ->and(json_encode($native, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('x', 64));
});

it('bounds untrusted reasons while reading cache-backed persisted native results', function () {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.history.driver', 'cache');
    config()->set('swarm.native_results.max_bytes', 256);
    app()->forgetInstance(SwarmCapture::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(RunHistoryStore::class);
    RichNativeAgent::$response = richNativeStepResponse();
    $response = RichNativeSequentialSwarm::make()->prompt('cache reasons bound');
    $key = (string) config('swarm.history.prefix', 'swarm:history:').$response->metadata['run_id'];
    $cache = Cache::store(config('swarm.history.store'));
    $raw = $cache->get($key);
    $raw['steps'][0]['native_result'] = [
        'format_version' => 1,
        'status' => 'partial',
        'reasons' => [str_repeat('untrusted-reason-', 256)],
    ];
    $cache->put($key, $raw, 3600);

    $native = app(RunHistoryStore::class)->find($response->metadata['run_id'])['steps'][0]['native_result'];

    expect(strlen(json_encode($native, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(256)
        ->and($native['status'])->toBe(NativeStepResult::PARTIAL)
        ->and($native['reasons'])->toBe(['limit']);
});

it('canonicalizes unavailable cache-backed persisted native results before returning them', function () {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.history.driver', 'cache');
    config()->set('swarm.native_results.max_bytes', 256);
    app()->forgetInstance(SwarmCapture::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(RunHistoryStore::class);
    RichNativeAgent::$response = richNativeStepResponse();
    $response = RichNativeSequentialSwarm::make()->prompt('cache unavailable bound');
    $key = (string) config('swarm.history.prefix', 'swarm:history:').$response->metadata['run_id'];
    $cache = Cache::store(config('swarm.history.store'));
    $raw = $cache->get($key);
    $raw['steps'][0]['native_result'] = [
        'format_version' => 1,
        'status' => 'unavailable',
        'reasons' => [str_repeat('untrusted-reason-', 256)],
        'structured' => ['raw_provider_payload' => str_repeat('secret-', 512)],
    ];
    $cache->put($key, $raw, 3600);

    $native = app(RunHistoryStore::class)->find($response->metadata['run_id'])['steps'][0]['native_result'];

    expect(strlen(json_encode($native, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(256)
        ->and($native)->toBe(NativeStepResult::unavailable(['limit'])->toArray())
        ->and(json_encode($native, JSON_THROW_ON_ERROR))->not->toContain('raw_provider_payload', 'secret');
});

it('rejects arbitrary objects in cache-backed persisted generation usage', function () {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.history.driver', 'cache');
    app()->forgetInstance(SwarmCapture::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(RunHistoryStore::class);
    RichNativeAgent::$response = richNativeStepResponse();
    $response = RichNativeSequentialSwarm::make()->prompt('cache usage shape');
    $key = (string) config('swarm.history.prefix', 'swarm:history:').$response->metadata['run_id'];
    $cache = Cache::store(config('swarm.history.store'));
    $raw = $cache->get($key);
    $raw['steps'][0]['native_result'] = [
        'format_version' => 1,
        'status' => 'available',
        'generation_steps' => [[
            'usage' => ['raw' => (object) ['raw_provider_payload' => 'secret']],
        ]],
    ];
    $cache->put($key, $raw, 3600);

    $native = app(RunHistoryStore::class)->find($response->metadata['run_id'])['steps'][0]['native_result'];

    expect($native['status'])->toBe(NativeStepResult::PARTIAL)
        ->and($native['reasons'])->toContain('unsupported_type')
        ->and($native['generation_steps'][0])->not->toHaveKey('usage')
        ->and(json_encode($native, JSON_THROW_ON_ERROR))->not->toContain('raw_provider_payload', 'secret');
});

it('rejects arbitrary objects and undocumented tool statuses in cache-backed persisted fields', function () {
    config()->set('swarm.persistence.driver', 'cache');
    config()->set('swarm.history.driver', 'cache');
    app()->forgetInstance(SwarmCapture::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(RunHistoryStore::class);
    RichNativeAgent::$response = richNativeStepResponse();
    $response = RichNativeSequentialSwarm::make()->prompt('cache scalar shape');
    $key = (string) config('swarm.history.prefix', 'swarm:history:').$response->metadata['run_id'];
    $cache = Cache::store(config('swarm.history.store'));
    $raw = $cache->get($key);
    $raw['steps'][0]['native_result'] = [
        'format_version' => 1,
        'status' => 'available',
        'generation_steps' => [[
            'text' => (object) ['raw_provider_payload' => 'generation-text-secret'],
            'provider' => (object) ['raw_provider_payload' => 'generation-provider-secret'],
        ]],
        'tools' => [[
            'name' => (object) ['raw_provider_payload' => 'tool-name-secret'],
            'status' => 'provider-private-status',
        ]],
    ];
    $cache->put($key, $raw, 3600);

    $native = app(RunHistoryStore::class)->find($response->metadata['run_id'])['steps'][0]['native_result'];
    $encoded = json_encode($native, JSON_THROW_ON_ERROR);

    expect($native['status'])->toBe(NativeStepResult::PARTIAL)
        ->and($native['reasons'])->toContain('unsupported_type')
        ->and($native['generation_steps'][0])->not->toHaveKeys(['text', 'provider'])
        ->and($native['tools'][0])->not->toHaveKeys(['name', 'status'])
        ->and($encoded)->not->toContain('raw_provider_payload', 'secret', 'provider-private-status');
});

it('does not let persisted Redact or Skip statuses reauthorize withheld content', function () {
    $projector = app(NativeStepResultProjector::class);
    $payload = [
        'format_version' => 1,
        'structured' => ['secret' => true],
        'reasoning' => 'reasoning-secret',
        'conversation_id' => 'conversation-secret',
        'generation_steps' => [['text' => 'generation-secret', 'usage' => ['input_tokens' => 2, 'output_tokens' => 1]]],
    ];

    $redacted = $projector->fromPersistedArray([...$payload, 'status' => 'redacted']);
    $omitted = $projector->fromPersistedArray([...$payload, 'status' => 'omitted']);

    expect($redacted->status)->toBe(NativeStepResult::REDACTED)
        ->and($redacted->toArray())->not->toHaveKeys(['structured', 'reasoning', 'conversation_id'])
        ->and($redacted->generationSteps[0])->not->toHaveKeys(['text', 'structured', 'reasoning'])
        ->and($omitted->toArray())->toBe(NativeStepResult::omitted()->toArray())
        ->and(json_encode([$redacted, $omitted], JSON_THROW_ON_ERROR))->not->toContain('secret');
});

it('seals persisted native results without hiding them from authorized history reads', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(SwarmRunner::class);
    RichNativeAgent::$response = richNativeStepResponse('sealed-invocation');

    $response = RichNativeSequentialSwarm::make()->prompt('sealed');
    $stored = (string) DB::table('swarm_run_steps')
        ->where('run_id', $response->metadata['run_id'])
        ->value('native_result');
    $history = app(RunHistoryStore::class)->find($response->metadata['run_id']);

    expect($stored)->toStartWith('sw0:')
        ->and($stored)->not->toContain('reasoning-secret', 'conversation-row', 'step-reasoning-secret')
        ->and($history['steps'][0]['native_result']['invocation_id'])->toBe('sealed-invocation')
        ->and($history['steps'][0]['native_result']['reasoning'])->toBe('reasoning-secret');
});

it('seals checkpoint durable and replay native-result locations and restores them through readers', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.streaming.replay.enabled', true);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(DatabaseStreamStepCheckpointStore::class);
    app()->forgetInstance(SwarmRunner::class);
    $native = app(NativeStepResultProjector::class)->fromResponse(richNativeStepResponse('sealed-everywhere'));

    $checkpointContext = RunContext::from('checkpoint', 'sealed-checkpoint');
    app(RunHistoryStore::class)->start('sealed-checkpoint', RichNativeSequentialSwarm::class, 'sequential', $checkpointContext, [], 3600);
    $checkpoints = app(DatabaseStreamStepCheckpointStore::class);
    $checkpoints->recordWithNativeResult('sealed-checkpoint', 0, 'out', [], new CitationEvidence, $native);
    $rawCheckpoint = (string) DB::table('swarm_stream_step_checkpoints')->value('native_result');
    expect($rawCheckpoint)->toStartWith('sw0:')->not->toContain('reasoning-secret', 'conversation-row')
        ->and($checkpoints->findWithNativeResult('sealed-checkpoint', 0)?->nativeResult?->reasoning)->toBe('reasoning-secret');

    $runId = FakeParallelSwarm::make()->dispatchDurable('sealed durable')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $durable = app(DurableRunStore::class);
    $branch = $durable->branchesFor($runId, 'parallel')[0];
    DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', $branch['branch_id'])->update([
        'status' => 'running',
        'execution_token' => 'sealed-branch-token',
        'leased_until' => now()->addMinute(),
    ]);
    expect($durable)->toBeInstanceOf(StoresDurableNativeStepResults::class);
    $durable->markBranchCompletedWithNativeResult(
        $runId,
        $branch['branch_id'],
        'sealed-branch-token',
        'out',
        [],
        1,
        new CitationEvidence,
        $native,
    );
    $rawBranch = (string) DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', $branch['branch_id'])->value('native_result');
    expect($rawBranch)->toStartWith('sw0:')
        ->and($durable->findBranch($runId, $branch['branch_id'])['native_result']['invocation_id'])->not->toBeNull();

    expect($durable)->toBeInstanceOf(DatabaseDurableRunStore::class);
    $durable->storeHierarchicalNodeOutputWithNativeResult($runId, 'sealed-node', 'out', 3600, new CitationEvidence, $native);
    $rawNode = (string) DB::table('swarm_durable_node_outputs')->where('run_id', $runId)->where('node_id', 'sealed-node')->value('native_result');
    expect($rawNode)->toStartWith('sw0:')->not->toContain('reasoning-secret', 'conversation-row')
        ->and($durable->hierarchicalNodeOutputsForInspection($runId)[0]['native_result']['reasoning'])->toBe('reasoning-secret');

    $stream = app(SwarmRunner::class)->agent(new NativeResultAgent)->stream(RunContext::from('sealed-replay', 'sealed-replay'));
    iterator_to_array($stream);
    $rawEvent = DB::table('swarm_stream_events')->where('run_id', 'sealed-replay')->where('event_type', 'swarm_step_end')->value('payload');
    expect((string) $rawEvent)->toContain('sw0:')->not->toContain('reason-secret', 'argument-secret');
    $replayed = collect(iterator_to_array(app(SwarmHistory::class)->replay('sealed-replay')))
        ->whereInstanceOf(SwarmStepEnd::class)
        ->sole();
    expect($replayed->nativeResult?->reasoning)->toBe('reason-secret');
});

it('applies the additive migration idempotently to configured table names', function () {
    $defaults = config('swarm.tables');
    $tables = [
        'history_steps' => 'test_native_history_steps',
        'durable_branches' => 'test_native_durable_branches',
        'durable_node_outputs' => 'test_native_node_outputs',
        'stream_step_checkpoints' => 'test_native_stream_checkpoints',
    ];

    foreach ($tables as $key => $table) {
        Schema::create($table, fn ($blueprint) => $blueprint->id());
        config()->set('swarm.tables.'.$key, $table);
    }

    try {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_25_000001_add_swarm_native_step_results.php';
        $migration->up();
        $migration->up();
        foreach ($tables as $table) {
            expect(Schema::hasColumns($table, ['native_result_status', 'native_result']))->toBeTrue();
        }
        $migration->down();
        $migration->down();
        foreach ($tables as $table) {
            expect(Schema::hasColumn($table, 'native_result_status'))->toBeFalse()
                ->and(Schema::hasColumn($table, 'native_result'))->toBeFalse();
        }
    } finally {
        config()->set('swarm.tables', $defaults);
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
});

it('checks native-result migration readiness through the existing health command', function (string $table, string $column, bool $durable, string $component) {
    Schema::table($table, fn ($blueprint) => $blueprint->dropColumn($column));

    expect(Artisan::call('swarm:health', ['--json' => true, '--durable' => $durable]))->toBe(1);
    $checks = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks']);
    $check = $checks->firstWhere('component', $component);

    expect($check['status'])->toBe('failed')
        ->and($check['details'])->toContain($table.'.native_result_status', $table.'.native_result', 'Run migrations');
})->with([
    ['swarm_run_steps', 'native_result_status', false, 'History'],
    ['swarm_run_steps', 'native_result', false, 'History'],
    ['swarm_durable_branches', 'native_result_status', true, 'Durable runtime'],
    ['swarm_durable_branches', 'native_result', true, 'Durable runtime'],
    ['swarm_durable_node_outputs', 'native_result_status', true, 'Durable runtime'],
    ['swarm_durable_node_outputs', 'native_result', true, 'Durable runtime'],
    ['swarm_stream_step_checkpoints', 'native_result_status', false, 'Stream checkpoints'],
    ['swarm_stream_step_checkpoints', 'native_result', false, 'Stream checkpoints'],
]);

it('uses additive configured columns and prunes native results with their parent step', function () {
    expect(Schema::hasColumns(config('swarm.tables.history_steps'), ['native_result_status', 'native_result']))->toBeTrue()
        ->and(Schema::hasColumns(config('swarm.tables.stream_step_checkpoints'), ['native_result_status', 'native_result']))->toBeTrue()
        ->and(Schema::hasColumns(config('swarm.tables.durable_branches'), ['native_result_status', 'native_result']))->toBeTrue()
        ->and(Schema::hasColumns(config('swarm.tables.durable_node_outputs'), ['native_result_status', 'native_result']))->toBeTrue();

    RichNativeAgent::$response = richNativeStepResponse();
    $response = app(SwarmRunner::class)->agent(new RichNativeAgent)->prompt('prune native');
    DB::table('swarm_run_histories')->where('run_id', $response->metadata['run_id'])->update([
        'expires_at' => now()->subMinute(),
        'status' => 'completed',
    ]);
    DB::table('swarm_run_steps')->where('run_id', $response->metadata['run_id'])->update(['expires_at' => now()->subMinute()]);

    Artisan::call('swarm:prune');
    expect(DB::table('swarm_run_steps')->where('run_id', $response->metadata['run_id'])->exists())->toBeFalse();
});

it('demonstrates why mixed-version writers make code rollback unsafe after native results exist', function () {
    RichNativeAgent::$response = richNativeStepResponse('new-writer-native-id');
    $response = RichNativeSequentialSwarm::make()->prompt('new writer');
    $runId = $response->metadata['run_id'];

    // Emulate a pre-v0.28 upsert: it updates the old columns and omits the new
    // nullable evidence columns, so the prior native result remains stale.
    DB::table('swarm_run_steps')->where('run_id', $runId)->where('step_index', 0)->update([
        'output' => 'old-worker-retry-output',
    ]);

    $row = DB::table('swarm_run_steps')->where('run_id', $runId)->where('step_index', 0)->first();
    $history = app(RunHistoryStore::class)->find($runId);

    expect($row?->output)->toBe('old-worker-retry-output')
        ->and($history['steps'][0]['native_result']['invocation_id'])->toBe('new-writer-native-id');
});
