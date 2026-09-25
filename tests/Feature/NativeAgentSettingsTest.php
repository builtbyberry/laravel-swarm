<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\AuthorizesNativeAgentConversation;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeAgentSettingsDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeNativeAgentSettingsSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentConversation;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentInvoker;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentSettingsAttempt;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentToolFactoryReference;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentToolReference;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManager;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManifest;
use BuiltByBerry\LaravelSwarm\Support\NativeInputRecipient;
use BuiltByBerry\LaravelSwarm\Support\NativeMessageCodec;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\ConversationAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\NativeSettingsParticipant;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\NativeSettingsToolFactory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\AuthoredNativeSettingsParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Tools\NativeSettingsTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

beforeEach(function () {
    config()->set('swarm.native_inputs.enabled', true);
    config()->set('swarm.native_agent_settings.enabled', true);
    config()->set('swarm.native_inputs.disk', 'native-settings-test');
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    Storage::fake('native-settings-test');
    NativeSettingsToolFactory::$calls = 0;
});

test('native message codec preserves every reconstructible Laravel AI message field', function () {
    $messages = [
        new UserMessage('user', [new Base64Image(base64_encode('pixels'), 'image/png')]),
        new AssistantMessage('assistant', new Collection([
            new ToolCall('call-1', 'lookup', ['tenant' => 7], 'result-1', 'reason-1', [['text' => 'summary']], 'sealed', 'sig'),
        ]), [['type' => 'reasoning', 'id' => 'block-1']], 'openai'),
        new ToolResultMessage(new Collection([
            new ToolResult('call-1', 'lookup', ['tenant' => 7], ['ok' => true], 'result-1'),
        ])),
    ];

    $decoded = array_map(
        static fn ($message) => NativeMessageCodec::decode(NativeMessageCodec::encode($message)),
        $messages,
    );

    expect($decoded[0])->toBeInstanceOf(UserMessage::class)
        ->and($decoded[0]->attachments->first())->toBeInstanceOf(Base64Image::class)
        ->and($decoded[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($decoded[1]->toolCalls->first()->toArray())->toBe($messages[1]->toolCalls->first()->toArray())
        ->and($decoded[1]->replayBlocks)->toBe($messages[1]->replayBlocks)
        ->and($decoded[2])->toBeInstanceOf(ToolResultMessage::class)
        ->and($decoded[2]->toolResults->first()->toArray())->toBe($messages[2]->toolResults->first()->toArray());
});

test('registered factories expand exactly once into a sealed v2 tool descriptor', function () {
    config()->set('swarm.native_agent_settings.tool_factories.tenant-tools', NativeSettingsToolFactory::class);
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withTools([
            new NativeAgentToolFactoryReference('tenant-tools', ['tenant' => 'alpha']),
        ]),
    ]);

    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $row = app(NativeInputStore::class)->find((string) $context->nativeInputReference());

    expect(NativeSettingsToolFactory::$calls)->toBe(1)
        ->and($row['format_version'])->toBe(NativeInputManifest::SETTINGS_VERSION)
        ->and($row['payload']['recipients'][0]['tools'][0])->toBe([
            'class' => NativeSettingsTool::class,
            'arguments' => ['tenant' => 'alpha'],
        ]);
});

test('invalid tool references fail during admission before dispatch', function () {
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withTools([
            new NativeAgentToolReference(stdClass::class),
        ]),
    ]);

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'must resolve to a Laravel AI Agent, Tool, or ProviderTool');
});

test('v2 writers are independently default off while admitted v2 work keeps draining', function () {
    config()->set('swarm.native_agent_settings.enabled', false);
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withTools([new NativeAgentToolReference(NativeSettingsTool::class)]),
    ]);

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'Native per-run agent settings are disabled');

    config()->set('swarm.native_agent_settings.enabled', true);
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    config()->set('swarm.native_agent_settings.enabled', false);

    $fresh = RunContext::fromPayload($context->toQueuePayload());
    expect($fresh->nativeInvocation('parallel:0', 'inspect')->tools)->toHaveCount(1);
});

test('queue dispatch uses the v2 capability marker without exposing operational settings', function () {
    Bus::fake();
    $context = RunContext::fromTask('secret task')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)->withTools([
            new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'secret-tenant']),
        ]),
    ]);

    $queued = FakeSequentialSwarm::make()->queue($context);
    $payload = $queued->getJob()->task;

    expect($queued->getJob())->toBeInstanceOf(InvokeNativeAgentSettingsSwarm::class)
        ->and(json_encode($payload))->not->toContain('secret-tenant')
        ->and($payload)->toHaveKey('native_input_ref');
});

test('native settings reach the Laravel AI prompt and do not leak into the next run', function () {
    FakeResearcher::fake(['configured', 'plain']);
    FakeWriter::fake(['writer-1', 'writer-2']);
    FakeEditor::fake(['editor-1', 'editor-2']);

    $configured = RunContext::fromTask('configured-task')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)
            ->withInvocation('openai', 'gpt-settings', 17)
            ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'alpha'])])
            ->withMessages([new UserMessage('prior turn')]),
    ]);

    FakeSequentialSwarm::make()->prompt($configured);
    FakeSequentialSwarm::make()->prompt('plain-task');

    FakeResearcher::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'configured-task'
        && $prompt->model === 'gpt-settings'
        && $prompt->timeout === 17
        && $prompt->messages[0]->content === 'prior turn'
        && $prompt->tools[0] instanceof NativeSettingsTool
        && $prompt->tools[0]->tenant === 'alpha');
    FakeResearcher::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'plain-task'
        && $prompt->messages === null
        && $prompt->tools === null);
});

test('reconstructed tools and one-shot messages match the direct Laravel AI request wire', function () {
    config()->set('ai.providers.openai.key', 'test-key');
    $requests = [];
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$requests) {
        $requests[] = $request->data();

        return Http::response([
            'id' => 'completed',
            'model' => 'gpt-settings',
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'done']]]],
            'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
        ]);
    });

    (new FakeResearcher)
        ->withTools([new NativeSettingsTool('tenant-wire')])
        ->withMessages([new UserMessage('prior-wire')])
        ->prompt('wire-task', [], 'openai', 'gpt-settings', 19);

    $context = RunContext::fromTask('wire-task')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)
            ->withInvocation('openai', 'gpt-settings', 19)
            ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'tenant-wire'])])
            ->withMessages([new UserMessage('prior-wire')]),
    ]);
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $invocation = RunContext::fromPayload($context->toQueuePayload())
        ->nativeInvocation('parallel:0', 'wire-task', new NativeAgentSettingsAttempt);
    NativeAgentInvoker::prompt(new FakeResearcher, $invocation);

    expect($requests)->toHaveCount(2)
        ->and($requests[1])->toEqual($requests[0])
        ->and($requests[1]['tools'][0]['name'])->toBe('NativeSettingsTool')
        ->and(json_encode($requests[1]['input'], JSON_THROW_ON_ERROR))->toContain('prior-wire');
});

test('long-lived worker agent instances stay isolated across interleaved tenant runs', function () {
    FakeResearcher::fake(['tenant-a', 'tenant-b', 'plain']);
    $sharedAgent = new FakeResearcher;
    $runner = app(SwarmRunner::class);

    foreach (['tenant-a', 'tenant-b'] as $tenant) {
        $context = RunContext::fromTask($tenant)->withAgentConfiguration([
            NativeInputRecipient::sequential(0)
                ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => $tenant])])
                ->withMessages([new UserMessage($tenant.'-history')]),
        ]);
        $runner->agent($sharedAgent)->prompt($context);
    }
    $runner->agent($sharedAgent)->prompt('plain');

    foreach (['tenant-a', 'tenant-b'] as $tenant) {
        FakeResearcher::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === $tenant
            && $prompt->messages[0]->content === $tenant.'-history'
            && $prompt->tools[0] instanceof NativeSettingsTool
            && $prompt->tools[0]->tenant === $tenant);
    }
    FakeResearcher::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'plain'
        && $prompt->messages === null
        && $prompt->tools === null);
});

test('queued worker reconstruction preserves native tools and messages', function () {
    FakeResearcher::fake(['research']);
    FakeWriter::fake(['writer']);
    FakeEditor::fake(['editor']);
    $context = RunContext::fromTask('queued-settings')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)
            ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'queue'])])
            ->withMessages([new UserMessage('queued history')]),
    ]);

    $job = FakeSequentialSwarm::make()->queue($context)->getJob();
    expect($job)->toBeInstanceOf(InvokeNativeAgentSettingsSwarm::class);
    $job->handle(app(SwarmRunner::class));

    FakeResearcher::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->messages[0]->content === 'queued history'
        && $prompt->tools[0] instanceof NativeSettingsTool
        && $prompt->tools[0]->tenant === 'queue');
});

test('durable worker reconstruction preserves settings and commits messages at checkpoints', function () {
    FakeResearcher::fake(['research']);
    FakeWriter::fake(['writer']);
    FakeEditor::fake(['editor']);
    $context = RunContext::fromTask('durable-settings')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)
            ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'durable'])])
            ->withMessages([new UserMessage('durable history')]),
    ]);

    $response = FakeSequentialSwarm::make()->dispatchDurable($context);
    expect($response->getJob())->toBeInstanceOf(AdvanceNativeAgentSettingsDurableSwarm::class);

    (new AdvanceNativeAgentSettingsDurableSwarm($response->runId, 0))->handle(app(DurableSwarmManager::class));
    FakeResearcher::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->messages[0]->content === 'durable history'
        && $prompt->tools[0] instanceof NativeSettingsTool
        && $prompt->tools[0]->tenant === 'durable');

    $run = app(DurableSwarmManager::class)->find($response->runId);
    expect($run['status'])->toBe('pending');

    $fresh = RunContext::fromPayload(app(ContextStore::class)->find($response->runId));
    expect($fresh->nativeInvocation('sequential:0', 'retry')->messages)->toBeEmpty();
});

test('withMessages is one-shot while withTools remains persistent in an execution attempt', function () {
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)
            ->withTools([new NativeAgentToolReference(NativeSettingsTool::class)])
            ->withMessages([new UserMessage('once')]),
    ]);
    app(NativeInputManager::class)->admit($context, Topology::Sequential, ExecutionMode::Run);
    $attempt = new NativeAgentSettingsAttempt;

    $first = $context->nativeInvocation('sequential:0', 'one', $attempt);
    $second = $context->nativeInvocation('sequential:0', 'two', $attempt);

    expect($first->messages)->toHaveCount(1)
        ->and($first->tools)->toHaveCount(1)
        ->and($second->messages)->toBeEmpty()
        ->and($second->clearMessages)->toBeTrue()
        ->and($second->tools)->toHaveCount(1);
});

test('committed one-shot state survives worker reconstruction while failed attempts replay', function () {
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)
            ->withTools([new NativeAgentToolReference(NativeSettingsTool::class)])
            ->withMessages([new UserMessage('once')]),
    ]);
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $payload = $context->toQueuePayload();

    $failedAttempt = new NativeAgentSettingsAttempt;
    $fresh = RunContext::fromPayload($payload);
    expect($fresh->nativeInvocation('parallel:0', 'first', $failedAttempt)->messages)->toHaveCount(1)
        ->and(RunContext::fromPayload($payload)->nativeInvocation('parallel:0', 'retry', new NativeAgentSettingsAttempt)->messages)->toHaveCount(1);

    app(NativeInputManager::class)->commitConsumedMessages($fresh, $failedAttempt);
    $afterCommit = RunContext::fromPayload($payload)->nativeInvocation('parallel:0', 'continued', new NativeAgentSettingsAttempt);

    expect($afterCommit->messages)->toBeEmpty()
        ->and($afterCommit->clearMessages)->toBeTrue()
        ->and($afterCommit->tools)->toHaveCount(1);
});

test('one-shot message consumption rolls back with its owning database boundary', function () {
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)->withMessages([new UserMessage('once')]),
    ]);
    app(NativeInputManager::class)->admit($context, Topology::Sequential, ExecutionMode::Queue);
    $attempt = new NativeAgentSettingsAttempt;
    RunContext::fromPayload($context->toQueuePayload())->nativeInvocation('sequential:0', 'first', $attempt);

    try {
        DB::transaction(function () use ($context, $attempt): void {
            app(NativeInputManager::class)->commitConsumedMessages($context, $attempt);
            throw new RuntimeException('roll back checkpoint');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('roll back checkpoint');
    }

    expect(RunContext::fromPayload($context->toQueuePayload())
        ->nativeInvocation('sequential:0', 'retry', new NativeAgentSettingsAttempt)->messages)->toHaveCount(1);

    DB::transaction(fn () => app(NativeInputManager::class)->commitConsumedMessages($context, $attempt));
    expect(RunContext::fromPayload($context->toQueuePayload())
        ->nativeInvocation('sequential:0', 'continued', new NativeAgentSettingsAttempt)->messages)->toBeEmpty();
});

test('recoverable withMessages attachments are promoted verified and pruned', function () {
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withMessages([
            new UserMessage('history', [new Base64Image(base64_encode('pixels'), 'image/png')]),
        ]),
    ]);
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);

    $row = app(NativeInputStore::class)
        ->find((string) $context->nativeInputReference());
    $descriptor = $row['payload']['recipients'][0]['messages'][0]['attachments'][0];

    expect($descriptor['type'])->toBe('stored-image')
        ->and($descriptor['swarm_owned'])->toBeTrue()
        ->and($descriptor['swarm_content_sha256'])->toBe(hash('sha256', 'pixels'))
        ->and(Storage::disk('native-settings-test')->exists($descriptor['path']))->toBeTrue();

    $invocation = RunContext::fromPayload($context->toQueuePayload())
        ->nativeInvocation('parallel:0', 'inspect', new NativeAgentSettingsAttempt);
    expect($invocation->messages[0]->attachments->first())->toBeInstanceOf(Base64Image::class);

    DB::table('swarm_native_inputs')->where('id', $context->nativeInputReference())
        ->update(['expires_at' => now()->subMinute()]);
    Artisan::call('swarm:prune');

    expect(Storage::disk('native-settings-test')->exists($descriptor['path']))->toBeFalse()
        ->and(DB::table('swarm_native_inputs')->where('id', $context->nativeInputReference())->exists())->toBeFalse();
});

test('sealed payload and version column disagreement fails before reconstruction', function () {
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withTools([new NativeAgentToolReference(NativeSettingsTool::class)]),
    ]);
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    DB::table('swarm_native_inputs')->where('id', $context->nativeInputReference())->update(['format_version' => 1]);

    expect(fn () => RunContext::fromPayload($context->toQueuePayload())->nativeInvocation('parallel:0', 'inspect'))
        ->toThrow(SwarmException::class, 'does not match sealed payload version');
});

test('ad-hoc parallel execution fails before discarding undeclared live instance state', function () {
    FakeResearcher::fake(['unused']);
    FakeWriter::fake(['unused']);

    expect(fn () => app(SwarmRunner::class)
        ->parallel([new FakeResearcher, new FakeWriter])
        ->prompt('inspect'))
        ->toThrow(SwarmException::class, 'live instance state cannot be preserved');
});

test('authored parallel workers reconstruct the declared configured instance', function () {
    FakeResearcher::fake(['research']);
    FakeWriter::fake(['writer']);

    AuthoredNativeSettingsParallelSwarm::make()->prompt('inspect');

    FakeResearcher::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->messages[0]->content === 'authored history'
        && $prompt->tools[0] instanceof NativeSettingsTool
        && $prompt->tools[0]->tenant === 'authored');
});

test('conversation access is authorized at admission and again before reconstructed invocation', function () {
    Schema::create('native_settings_participants', function (Blueprint $table): void {
        $table->id();
    });
    $participant = NativeSettingsParticipant::query()->create();
    $policy = new class implements AuthorizesNativeAgentConversation
    {
        public bool $allowed = true;

        public function authorize(?string $conversationId, object $participant, RunContext $context): bool
        {
            return $this->allowed && ($context->metadata['tenant_id'] ?? null) === 'tenant-a';
        }
    };
    $store = Mockery::mock(ConversationStore::class, VerifiesConversationOwnership::class);
    $store->shouldReceive('conversationBelongsTo')->once()->with('conversation-1', NativeSettingsParticipant::class, $participant->getKey())->andReturnTrue();
    app()->instance(AuthorizesNativeAgentConversation::class, $policy);
    app()->instance(ConversationStore::class, $store);
    app()->forgetInstance(NativeInputManager::class);

    $context = RunContext::fromTask('inspect')
        ->mergeMetadata(['tenant_id' => 'tenant-a'])
        ->withAgentConfiguration([
            NativeInputRecipient::parallel(0)->withConversation(
                NativeAgentConversation::continue('conversation-1', $participant),
            ),
        ]);
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);

    $policy->allowed = false;
    expect(fn () => RunContext::fromPayload($context->toQueuePayload())->nativeInvocation('parallel:0', 'inspect'))
        ->toThrow(SwarmException::class, 'not authorized for this actor or tenant');
});

test('native conversation settings call the Laravel AI conversation API for the request-local agent', function () {
    (require base_path('vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php'))->up();
    Schema::create('native_settings_participants', function (Blueprint $table): void {
        $table->id();
    });
    $participant = NativeSettingsParticipant::query()->create();
    app()->instance(AuthorizesNativeAgentConversation::class, new class implements AuthorizesNativeAgentConversation
    {
        public function authorize(?string $conversationId, object $participant, RunContext $context): bool
        {
            return true;
        }
    });
    app()->forgetInstance(NativeInputManager::class);
    ConversationAgent::fake(['ok']);

    $context = RunContext::fromTask('conversation')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)->withConversation(
            NativeAgentConversation::start($participant),
        ),
    ]);

    app(SwarmRunner::class)->agent(new ConversationAgent)->prompt($context);

    ConversationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent instanceof ConversationAgent
        && $prompt->agent->currentConversation() !== null
        && $prompt->agent->conversationParticipant()?->is($participant));
});

test('recoverable execution rejects new native conversations before dispatch', function () {
    Schema::create('native_settings_participants', function (Blueprint $table): void {
        $table->id();
    });
    $participant = NativeSettingsParticipant::query()->create();
    app()->instance(AuthorizesNativeAgentConversation::class, new class implements AuthorizesNativeAgentConversation
    {
        public function authorize(?string $conversationId, object $participant, RunContext $context): bool
        {
            return true;
        }
    });
    app()->forgetInstance(NativeInputManager::class);
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withConversation(NativeAgentConversation::start($participant)),
    ]);

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'require an existing conversation ID');
});
