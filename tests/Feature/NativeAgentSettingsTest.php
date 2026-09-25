<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\AuthorizesNativeAgentConversation;
use BuiltByBerry\LaravelSwarm\Contracts\AuthorizesNativeInputAttachment;
use BuiltByBerry\LaravelSwarm\Contracts\ConsumesNativeInputMessages;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeAgentSettingsDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeAgentSettingsDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastNativeAgentSettingsSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeNativeAgentSettingsSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeNativeAgentSettingsQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseNativeInputStore;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\DeclaredNativeSettingsToolAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\NativeSettingsParticipant;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\NativeSettingsToolFactory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\AuthoredNativeSettingsParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ConversationSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ConversationStaticHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RetryableNativeSettingsSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Tools\NativeSettingsTool;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use Closure;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
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
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
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
    config()->set('swarm.history.driver', 'database');
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
        new Message('user', 'generic'),
    ];

    $decoded = array_map(
        static fn ($message) => NativeMessageCodec::decode(NativeMessageCodec::encode($message)),
        $messages,
    );

    expect($decoded[0])->toBeInstanceOf(UserMessage::class)
        ->and($decoded[0]->content)->toBe('user')
        ->and($decoded[0]->attachments->first())->toBeInstanceOf(Base64Image::class)
        ->and($decoded[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($decoded[1]->content)->toBe('assistant')
        ->and($decoded[1]->toolCalls->first()->toArray())->toBe($messages[1]->toolCalls->first()->toArray())
        ->and($decoded[1]->replayBlocks)->toBe($messages[1]->replayBlocks)
        ->and($decoded[1]->replayBlocksProvider)->toBe('openai')
        ->and($decoded[2])->toBeInstanceOf(ToolResultMessage::class)
        ->and($decoded[2]->toolResults->first()->toArray())->toBe($messages[2]->toolResults->first()->toArray())
        ->and($decoded[3]->role->value)->toBe('user')
        ->and($decoded[3]->content)->toBe('generic');
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

test('durable recovery redispatch preserves settings until the retry commits', function () {
    $calls = 0;
    FakeResearcher::fake(function () use (&$calls): string {
        $calls++;

        if ($calls === 1) {
            throw new RuntimeException('retry native settings');
        }

        return 'recovered';
    });
    $context = RunContext::fromTask('durable-retry-settings')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)
            ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'recovery'])])
            ->withMessages([new UserMessage('retry history')]),
    ]);

    $response = RetryableNativeSettingsSwarm::make()->dispatchDurable($context);
    $manager = app(DurableSwarmManager::class);
    (new AdvanceNativeAgentSettingsDurableSwarm($response->runId, 0))->handle($manager);

    $storedAfterFailure = app(NativeInputStore::class)->find((string) $context->nativeInputReference());
    $retry = RunContext::fromPayload(app(ContextStore::class)->find($response->runId))
        ->nativeInvocation('sequential:0', 'retry', new NativeAgentSettingsAttempt);
    expect($manager->find($response->runId)['retry_attempt'])->toBe(1)
        ->and($storedAfterFailure['payload']['consumed_message_configuration_ids'] ?? null)->toBe([])
        ->and($retry->messages)->toHaveCount(1)
        ->and($retry->tools)->toHaveCount(1);

    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');

    expect($manager->find($response->runId)['status'])->toBe('completed')
        ->and($calls)->toBe(2)
        ->and(RunContext::fromPayload(app(ContextStore::class)->find($response->runId))
            ->nativeInvocation('sequential:0', 'continued', new NativeAgentSettingsAttempt)->messages)->toBeEmpty();
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
        ->and($second->messagesConfigured)->toBeFalse()
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
        ->and($afterCommit->messagesConfigured)->toBeFalse()
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

test('reconstructed workers continue an authorized native conversation', function () {
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
    ConversationAgent::fake(['started', 'continued']);

    app(SwarmRunner::class)->agent(new ConversationAgent)->prompt(
        RunContext::fromTask('start')->withAgentConfiguration([
            NativeInputRecipient::sequential(0)->withConversation(NativeAgentConversation::start($participant)),
        ]),
    );
    $conversationId = null;
    ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use (&$conversationId): bool {
        $conversationId = $prompt->agent->currentConversation();

        return $prompt->prompt === 'start';
    });
    expect($conversationId)->toBeString()->not->toBeEmpty();

    $context = RunContext::fromTask('continue')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withConversation(
            NativeAgentConversation::continue($conversationId, $participant),
        ),
    ]);
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $invocation = RunContext::fromPayload($context->toQueuePayload())
        ->nativeInvocation('parallel:0', 'continue', new NativeAgentSettingsAttempt);
    NativeAgentInvoker::prompt(new ConversationAgent, $invocation);

    ConversationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'continue'
        && $prompt->agent->currentConversation() === $conversationId
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

test('explicit empty native overrides survive request-local and reconstructed execution', function () {
    DeclaredNativeSettingsToolAgent::fake(['local', 'reconstructed']);

    $local = RunContext::fromTask('local')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)->withTools([])->withMessages([]),
    ]);
    app(SwarmRunner::class)->agent(new DeclaredNativeSettingsToolAgent)->prompt($local);

    DeclaredNativeSettingsToolAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'local'
        && $prompt->tools === []
        && $prompt->messages === []);

    $recoverable = RunContext::fromTask('reconstructed')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withTools([])->withMessages([]),
    ]);
    app(NativeInputManager::class)->admit($recoverable, Topology::Parallel, ExecutionMode::Queue);
    $invocation = RunContext::fromPayload($recoverable->toQueuePayload())
        ->nativeInvocation('parallel:0', 'reconstructed', new NativeAgentSettingsAttempt);

    expect($recoverable->nativeInput()?->formatVersion())->toBe(NativeInputManifest::SETTINGS_VERSION)
        ->and($invocation->toolsConfigured)->toBeTrue()
        ->and($invocation->messagesConfigured)->toBeTrue();
    NativeAgentInvoker::prompt(new DeclaredNativeSettingsToolAgent, $invocation);
    DeclaredNativeSettingsToolAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'reconstructed'
        && $prompt->tools === []
        && $prompt->messages === []);
});

test('native input and agent configuration compose without discarding or widening recipients', function () {
    $message = new UserMessage('inspect', [new Base64Image(base64_encode('pixels'), 'image/png')]);

    $inputThenSettings = RunContext::fromTask($message)
        ->withAgentInput($message, [NativeInputRecipient::sequential(1, attachments: [0])])
        ->withAgentConfiguration([
            NativeInputRecipient::sequential(0)->withTools([new NativeAgentToolReference(NativeSettingsTool::class)]),
        ]);
    $settingsThenInput = RunContext::fromTask('inspect')
        ->withAgentConfiguration([
            NativeInputRecipient::sequential(0)->withTools([new NativeAgentToolReference(NativeSettingsTool::class)]),
        ])
        ->withAgentInput($message, [NativeInputRecipient::sequential(1, attachments: [0])]);

    foreach ([$inputThenSettings, $settingsThenInput] as $context) {
        $settings = $context->nativeRecipient('sequential:0');
        $input = $context->nativeRecipient('sequential:1');
        expect($settings?->attachments)->toBe([])
            ->and($settings?->hasNativeSettings())->toBeTrue()
            ->and($input?->attachments)->toBe([0])
            ->and($input?->hasNativeSettings())->toBeFalse();
    }
});

test('same recipient native input and settings compose field by field in either order', function () {
    $message = new UserMessage('inspect', [new Base64Image(base64_encode('pixels'), 'image/png')]);
    $inputRecipient = NativeInputRecipient::sequential(0, attachments: [0])
        ->withInvocation(provider: 'openai', model: 'gpt-native', timeout: 37);
    $settingsRecipient = NativeInputRecipient::sequential(0)
        ->withTools([new NativeAgentToolReference(NativeSettingsTool::class)])
        ->withMessages([new UserMessage('prior context')]);

    $inputThenSettings = RunContext::fromTask($message)
        ->withAgentInput($message, [$inputRecipient])
        ->withAgentConfiguration([$settingsRecipient]);
    $settingsThenInput = RunContext::fromTask('inspect')
        ->withAgentConfiguration([$settingsRecipient])
        ->withAgentInput($message, [$inputRecipient]);

    foreach ([$inputThenSettings, $settingsThenInput] as $context) {
        $recipient = $context->nativeRecipient('sequential:0');

        expect($recipient?->attachments)->toBe([0])
            ->and($recipient?->provider)->toBe('openai')
            ->and($recipient?->model)->toBe('gpt-native')
            ->and($recipient?->timeout)->toBe(37)
            ->and($recipient?->toolsConfigured)->toBeTrue()
            ->and($recipient?->tools)->toHaveCount(1)
            ->and($recipient?->messagesConfigured)->toBeTrue()
            ->and($recipient?->messages)->toHaveCount(1);
    }
});

test('request-local withMessages attachments enforce authorization and byte limits', function () {
    Storage::disk('native-settings-test')->put('tenant/secret.txt', 'secret');
    app()->instance(AuthorizesNativeInputAttachment::class, new class implements AuthorizesNativeInputAttachment
    {
        public function authorize(File $attachment, RunContext $context): bool
        {
            return false;
        }
    });
    app()->forgetInstance(NativeInputManager::class);

    $stored = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)->withMessages([
            new UserMessage('history', [new StoredDocument('tenant/secret.txt', 'native-settings-test')]),
        ]),
    ]);
    expect(fn () => app(NativeInputManager::class)->admit($stored, Topology::Sequential, ExecutionMode::Run))
        ->toThrow(SwarmException::class, 'not authorized');

    app()->instance(AuthorizesNativeInputAttachment::class, new class implements AuthorizesNativeInputAttachment
    {
        public function authorize(File $attachment, RunContext $context): bool
        {
            return true;
        }
    });
    app()->forgetInstance(NativeInputManager::class);
    config()->set('swarm.native_inputs.max_attachment_bytes', 2);
    $oversized = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)->withMessages([
            new UserMessage('history', [new Base64Document(base64_encode('secret'), 'text/plain')]),
        ]),
    ]);
    expect(fn () => app(NativeInputManager::class)->admit($oversized, Topology::Sequential, ExecutionMode::Run))
        ->toThrow(SwarmException::class, 'byte limit');
});

test('encoded operational settings honor the aggregate input limit before persistence', function () {
    config()->set('swarm.limits.max_input_bytes', 32);
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withMessages([new UserMessage(str_repeat('x', 256))]),
    ]);

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'max_input_bytes');
    expect(DB::table('swarm_native_inputs')->count())->toBe(0)
        ->and(Storage::disk('native-settings-test')->allFiles())->toBeEmpty();
});

test('invocation-only declarations satisfy ad-hoc parallel reconstruction safety', function () {
    FakeResearcher::fake(['research']);
    FakeWriter::fake(['writer']);
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withInvocation('openai', 'model-a', 10),
        NativeInputRecipient::parallel(1)->withInvocation('openai', 'model-b', 11),
    ]);

    app(SwarmRunner::class)->parallel([new FakeResearcher, new FakeWriter])->prompt($context);

    FakeResearcher::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'model-a' && $prompt->timeout === 10);
    FakeWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'model-b' && $prompt->timeout === 11);
});

test('default-off rollout preserves ad-hoc hierarchical parallel execution', function () {
    config()->set('swarm.native_agent_settings.enabled', false);
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('parallel', [
            'parallel' => ['type' => 'parallel', 'branches' => ['writer', 'editor'], 'next' => 'finish'],
            'writer' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'writer'],
            'editor' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'editor'],
            'finish' => ['type' => 'finish', 'output_from' => 'editor'],
        ]),
    ]);
    FakeWriter::fake(['writer-output']);
    FakeEditor::fake(['editor-output']);

    $response = app(SwarmRunner::class)
        ->hierarchical(new FakeHierarchicalCoordinator, [new FakeWriter, new FakeEditor])
        ->prompt('inspect');

    expect((string) $response)->toBe('editor-output');
});

test('known conversational incompatibility fails before queue dispatch', function () {
    Bus::fake();
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)->withMessages([new UserMessage('history')]),
    ]);

    expect(fn () => ConversationSequentialSwarm::make()->queue($context))
        ->toThrow(SwarmException::class, 'cannot apply withMessages');
    Bus::assertNothingDispatched();
});

test('known static routed incompatibility fails before queue dispatch', function () {
    Bus::fake();

    expect(fn () => ConversationStaticHierarchicalSwarm::make()->queue(
        RunContext::fromTask('inspect')->withAgentConfiguration([
            NativeInputRecipient::staticNode('conversation')
                ->withMessages([new UserMessage('history')]),
        ]),
    ))->toThrow(SwarmException::class, 'cannot apply withMessages');

    Bus::assertNothingDispatched();
});

test('stream and queued broadcast apply settings through their public paths', function () {
    FakeResearcher::fake(['research', 'broadcast']);
    FakeWriter::fake(['writer']);
    FakeEditor::fake(['editor']);
    $context = RunContext::fromTask('stream-settings')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)
            ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'stream'])])
            ->withMessages([new UserMessage('stream-history')]),
    ]);

    iterator_to_array(FakeSequentialSwarm::make()->stream($context));
    FakeResearcher::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->messages[0]->content === 'stream-history'
        && $prompt->tools[0] instanceof NativeSettingsTool
        && $prompt->tools[0]->tenant === 'stream');

    Bus::fake();
    $broadcast = FakeSequentialSwarm::make()->broadcastOnQueue(
        RunContext::fromTask('broadcast')->withAgentConfiguration([
            NativeInputRecipient::sequential(0)->withTools([new NativeAgentToolReference(NativeSettingsTool::class)]),
        ]),
        new Channel('native-settings'),
    );
    expect($broadcast->getJob())->toBeInstanceOf(BroadcastNativeAgentSettingsSwarm::class)
        ->and($broadcast->getJob()->afterCommit)->toBeTrue();
});

test('durable dispatcher selects every v2 capability marker with queue metadata', function () {
    Bus::fake();
    $response = FakeSequentialSwarm::make()->dispatchDurable(
        RunContext::fromTask('durable-markers')->withAgentConfiguration([
            NativeInputRecipient::sequential(0)->withTools([new NativeAgentToolReference(NativeSettingsTool::class)]),
        ]),
    );
    $dispatcher = app(DurableJobDispatcher::class);

    $step = $dispatcher->makeStepJob($response->runId, 1, 'redis', 'steps');
    $branch = $dispatcher->makeBranchJob($response->runId, 'branch-1', 'redis', 'branches');
    $resume = $dispatcher->makeQueuedResumeJob($response->runId, 'redis', 'resume');

    expect($step)->toBeInstanceOf(AdvanceNativeAgentSettingsDurableSwarm::class)
        ->and($branch)->toBeInstanceOf(AdvanceNativeAgentSettingsDurableBranch::class)
        ->and($resume)->toBeInstanceOf(ResumeNativeAgentSettingsQueuedHierarchicalSwarm::class)
        ->and($step->afterCommit)->toBeTrue()
        ->and($branch->afterCommit)->toBeTrue()
        ->and($resume->afterCommit)->toBeTrue()
        ->and($step->connection)->toBe('redis')
        ->and($step->queue)->toBe('steps')
        ->and($branch->queue)->toBe('branches')
        ->and($resume->queue)->toBe('resume');
});

test('durable marker selection falls back to the sealed payload version for legacy custom stores', function () {
    $contexts = Mockery::mock(ContextStore::class);
    $contexts->shouldReceive('find')->once()->with('custom-store-run')->andReturn([
        'native_input_ref' => 'custom-store-ref',
    ]);
    $store = Mockery::mock(NativeInputStore::class);
    $store->shouldReceive('find')->once()->with('custom-store-ref')->andReturn([
        'run_id' => 'custom-store-run',
        'payload' => ['version' => NativeInputManifest::SETTINGS_VERSION],
        'hash' => 'hash',
        'state' => 'active',
        'expires_at' => time() + 60,
    ]);

    $job = (new DurableJobDispatcher(app(ConfigRepository::class), $contexts, $store))
        ->makeStepJob('custom-store-run', 1);

    expect($job)->toBeInstanceOf(AdvanceNativeAgentSettingsDurableSwarm::class)
        ->and($job->afterCommit)->toBeTrue();
});

test('unsaved conversation participants fail before recoverable dispatch', function () {
    $participant = new NativeSettingsParticipant;

    expect(fn () => NativeAgentConversation::continue('conversation-1', $participant)->toArray())
        ->toThrow(SwarmException::class, 'Save the participant before dispatch');
});

test('queued terminal history rolls back when one-shot consumption fails', function () {
    Bus::fake();
    FakeResearcher::fake(['research']);
    FakeWriter::fake(['writer']);
    FakeEditor::fake(['editor']);

    $database = app(DatabaseNativeInputStore::class);
    $failing = new class($database) implements ConsumesNativeInputMessages, NativeInputStore
    {
        public function __construct(private DatabaseNativeInputStore $database) {}

        public function put(string $id, string $runId, array $payload, string $hash, int $expiresAt): void
        {
            $this->database->put($id, $runId, $payload, $hash, $expiresAt);
        }

        public function find(string $id): ?array
        {
            return $this->database->find($id);
        }

        public function activate(string $id, string $runId): void
        {
            $this->database->activate($id, $runId);
        }

        public function revoke(string $id, string $runId): void
        {
            $this->database->revoke($id, $runId);
        }

        public function consumeMessages(string $id, string $runId, array $configurationIds): void
        {
            throw new RuntimeException('injected consumption failure');
        }

        public function transaction(Closure $callback): mixed
        {
            return $this->database->transaction($callback);
        }
    };
    app()->instance(NativeInputStore::class, $failing);
    app()->forgetInstance(NativeInputManager::class);
    app()->forgetInstance(SwarmRunner::class);

    $context = RunContext::fromTask('atomic-terminal')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)->withMessages([new UserMessage('once')]),
    ]);
    $job = FakeSequentialSwarm::make()->queue($context)->getJob();

    expect(fn () => $job->handle(app(SwarmRunner::class)))
        ->toThrow(RuntimeException::class, 'injected consumption failure');

    $history = DB::table('swarm_run_histories')->where('run_id', $context->runId)->first();
    expect($history?->status)->toBe('failed');
    $fresh = RunContext::fromPayload($context->toQueuePayload());
    expect($fresh->nativeInvocation('sequential:0', 'retry', new NativeAgentSettingsAttempt)->messages)
        ->toHaveCount(1);
});

test('conversation authorization is resolved freshly for each worker scope', function () {
    $participant = new stdClass;
    app()->instance(AuthorizesNativeAgentConversation::class, new class implements AuthorizesNativeAgentConversation
    {
        public function authorize(?string $conversationId, object $participant, RunContext $context): bool
        {
            return true;
        }
    });
    $manager = app(NativeInputManager::class);
    $allowed = RunContext::fromTask('allowed')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)->withConversation(NativeAgentConversation::start($participant)),
    ]);
    $manager->admit($allowed, Topology::Sequential, ExecutionMode::Run);

    app()->instance(AuthorizesNativeAgentConversation::class, new class implements AuthorizesNativeAgentConversation
    {
        public function authorize(?string $conversationId, object $participant, RunContext $context): bool
        {
            return false;
        }
    });
    $denied = RunContext::fromTask('denied')->withAgentConfiguration([
        NativeInputRecipient::sequential(0)->withConversation(NativeAgentConversation::start($participant)),
    ]);

    expect(fn () => $manager->admit($denied, Topology::Sequential, ExecutionMode::Run))
        ->toThrow(SwarmException::class, 'not authorized');
});

test('recoverable message attachments reject invocation profiles before dispatch', function () {
    $attachment = (new Base64Document(base64_encode('secret'), 'text/plain'))
        ->withHeaders(['Authorization' => 'Bearer secret']);
    $context = RunContext::fromTask('inspect')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withMessages([new UserMessage('history', [$attachment])]),
    ]);

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'cannot carry headers or provider options');
    expect(DB::table('swarm_native_inputs')->count())->toBe(0);
});

test('native settings release emits value-free structural audit evidence', function () {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);
    app()->forgetInstance(NativeInputManager::class);

    $context = RunContext::fromTask('never-audit-task')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)
            ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'never-audit-tenant'])])
            ->withMessages([new UserMessage('never-audit-message')]),
    ]);
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    RunContext::fromPayload($context->toQueuePayload())
        ->nativeInvocation('parallel:0', 'never-audit-topology', new NativeAgentSettingsAttempt);

    $record = $sink->recordsForCategory('native_input.released')[0] ?? [];
    expect($record)->toMatchArray([
        'tools_configured' => true,
        'tool_count' => 1,
        'messages_configured' => true,
        'message_count' => 1,
        'conversation_mode' => 'none',
    ])->and(json_encode($record))->not->toContain(
        'never-audit-task',
        'never-audit-tenant',
        'never-audit-message',
        (string) $context->nativeInputReference(),
    );
});
