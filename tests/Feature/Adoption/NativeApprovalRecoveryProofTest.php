<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery\CapabilityPreservingConversationStore;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery\ContractOnlyApprovalAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery\NativeApprovalAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery\NativeApprovalTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery\NativeApprovalWire;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery\NativeEditableApprovalTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery\NativeOrdinaryTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery\NativeRejectableApprovalTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery\PlainConversationStore;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery\ReconstructedHistoryAgent;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Storage\DatabaseConversationStore;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('ai.providers.openai-compatible.key', 'backup-key');
    config()->set('ai.providers.openai-compatible.url', 'https://backup.test/v1');
    config()->set('ai.conversations.generate_title', false);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    (require dirname(__DIR__, 3).'/vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php')->up();
    NativeApprovalAgent::$effects = [];
    Http::preventStrayRequests();
});

/**
 * @return list<array{id: string, call_id: string, name: string, arguments: array<string, mixed>}>
 */
function nativeApprovalProofCalls(): array
{
    return [
        ['id' => 'fc_ordinary', 'call_id' => 'call_ordinary', 'name' => class_basename(NativeOrdinaryTool::class), 'arguments' => ['value' => 'ordinary-original']],
        ['id' => 'fc_approve', 'call_id' => 'call_approve', 'name' => class_basename(NativeApprovalTool::class), 'arguments' => ['value' => 'approve-original']],
        ['id' => 'fc_edit', 'call_id' => 'call_edit', 'name' => class_basename(NativeEditableApprovalTool::class), 'arguments' => ['value' => 'edit-original']],
        ['id' => 'fc_reject', 'call_id' => 'call_reject', 'name' => class_basename(NativeRejectableApprovalTool::class), 'arguments' => ['value' => 'reject-original']],
    ];
}

it('pins the accepted Laravel AI 1.0.0 source while leaving later supported releases behavioral', function () {
    expect(InstalledVersions::satisfies(new VersionParser, 'laravel/ai', '^1.0'))->toBeTrue();

    if (InstalledVersions::getPrettyVersion('laravel/ai') === 'v1.0.0') {
        expect(InstalledVersions::getReference('laravel/ai'))->toBe('101c7ea33cd8569d82570f753fbf38e48b7d3d95');
    }
});

it('persists inspects owns and resumes native approvals with exact ordered wire history', function () {
    $inner = new DatabaseConversationStore('testing');
    $store = new CapabilityPreservingConversationStore($inner);
    app()->instance(ConversationStore::class, $store);
    $participant = (object) ['id' => 101];
    $calls = nativeApprovalProofCalls();
    $repeat = [['id' => 'fc_repeat', 'call_id' => 'call_repeat', 'name' => class_basename(NativeApprovalTool::class), 'arguments' => ['value' => 'repeat-original']]];
    $requests = [];
    Http::fake(function (Request $request) use (&$requests, $calls, $repeat) {
        $requests[] = $request->data();
        $number = count($requests);

        if ($number === 1) {
            return Http::response(NativeApprovalWire::toolCalls($calls));
        }

        $input = $request['input'];
        expect($input[0])->toBe(['role' => 'system', 'content' => 'Exercise Laravel AI native tool approval contracts.'])
            ->and($input[1])->toBe(['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'perform controlled actions']]])
            ->and(count(array_filter($input, fn (mixed $item): bool => is_array($item) && ($item['role'] ?? null) === 'user')))->toBe(1);

        $initialBlocks = NativeApprovalWire::toolCalls($calls)['output'];
        expect(array_slice($input, 2, 4))->toBe($initialBlocks)
            ->and(array_slice($input, 6, 4))->toBe([
                ['type' => 'function_call_output', 'call_id' => 'call_ordinary', 'output' => 'ordinary:ordinary-original'],
                ['type' => 'function_call_output', 'call_id' => 'call_approve', 'output' => 'approved:approve-original'],
                ['type' => 'function_call_output', 'call_id' => 'call_edit', 'output' => 'edited:edit-revised'],
                ['type' => 'function_call_output', 'call_id' => 'call_reject', 'output' => 'policy rejected'],
            ]);

        $stored = json_decode(DB::table('agent_conversation_messages')->where('role', 'assistant')->sole()->steps, true, flags: JSON_THROW_ON_ERROR);
        expect($stored[0]['tool_calls'][1])->toMatchArray(['id' => 'fc_approve', 'result' => 'approved:approve-original'])
            ->and($stored[0]['tool_calls'][2])->toMatchArray(['id' => 'fc_edit', 'arguments' => ['value' => 'edit-revised'], 'result' => 'edited:edit-revised'])
            ->and($stored[0]['tool_calls'][3])->toMatchArray(['id' => 'fc_reject', 'result' => 'policy rejected', 'denied' => true]);

        if ($number === 2) {
            return Http::response(NativeApprovalWire::toolCalls($repeat, 'response-repeat'));
        }

        expect(array_slice($input, 10, 1))->toBe(NativeApprovalWire::toolCalls($repeat, 'response-repeat')['output'])
            ->and(array_slice($input, 11))->toBe([
                ['type' => 'function_call_output', 'call_id' => 'call_repeat', 'output' => 'approved:repeat-original'],
            ]);

        return Http::response(NativeApprovalWire::final());
    });

    $resolvedEvents = [];
    app('events')->listen(ToolApprovalResolved::class, function (ToolApprovalResolved $event) use (&$resolvedEvents): void {
        $resolvedEvents[] = $event;
    });

    $agent = (new NativeApprovalAgent)->forParticipant($participant);
    $paused = $agent->prompt('perform controlled actions');
    $conversationId = $agent->currentConversation();

    expect($conversationId)->not->toBeNull()
        ->and($store)->toBeInstanceOf(ResolvesPendingApprovals::class)
        ->and($store)->toBeInstanceOf(VerifiesConversationOwnership::class)
        ->and($store->conversationBelongsTo($conversationId, $participant::class, 101))->toBeTrue()
        ->and(array_map(fn ($approval) => $approval->toArray(), $store->pendingApprovalsFor($conversationId)))->toBe([
            ['id' => 'fc_approve', 'tool' => class_basename(NativeApprovalTool::class), 'arguments' => ['value' => 'approve-original'], 'reason' => null],
            ['id' => 'fc_edit', 'tool' => class_basename(NativeEditableApprovalTool::class), 'arguments' => ['value' => 'edit-original'], 'reason' => null],
            ['id' => 'fc_reject', 'tool' => class_basename(NativeRejectableApprovalTool::class), 'arguments' => ['value' => 'reject-original'], 'reason' => null],
        ])
        ->and($paused->hasPendingApprovals())->toBeTrue();

    $decisions = Decisions::from([
        'fc_approve' => Decision::approve(),
        'fc_edit' => Decision::edit(['value' => 'edit-revised']),
        'fc_reject' => Decision::reject('policy rejected'),
    ]);
    $pausedAgain = $agent->prompt($decisions);
    expect(array_map(fn ($approval) => $approval->id, $store->pendingApprovalsFor($conversationId)))->toBe(['fc_repeat'])
        ->and($pausedAgain->hasPendingApprovals())->toBeTrue()
        ->and($store->approvalWrites)->toHaveCount(1);

    $final = $agent->prompt(Decisions::from(['fc_repeat' => Decision::approve()]));
    expect($final->text)->toBe('continued')
        ->and($store->pendingApprovalsFor($conversationId))->toBe([])
        ->and($store->approvalWrites)->toHaveCount(2)
        ->and($resolvedEvents)->toHaveCount(2)
        ->and(NativeApprovalAgent::$effects)->toBe([
            ['tool' => 'ordinary', 'id' => 'fc_ordinary', 'value' => 'ordinary-original'],
            ['tool' => 'approve', 'id' => 'fc_approve', 'value' => 'approve-original'],
            ['tool' => 'edit', 'id' => 'fc_edit', 'value' => 'edit-revised'],
            ['tool' => 'approve', 'id' => 'fc_repeat', 'value' => 'repeat-original'],
        ]);

    expect(fn () => $agent->prompt($decisions))->toThrow(ApprovalMismatchException::class, 'already-resolved');
    Http::assertSentCount(3);
});

it('rejects a partial decision set with the native mismatch and performs no gated effect', function () {
    app()->instance(ConversationStore::class, new DatabaseConversationStore('testing'));
    $calls = [
        ['id' => 'fc_first', 'call_id' => 'call_first', 'name' => class_basename(NativeApprovalTool::class), 'arguments' => ['value' => 'first']],
        ['id' => 'fc_second', 'call_id' => 'call_second', 'name' => class_basename(NativeEditableApprovalTool::class), 'arguments' => ['value' => 'second']],
    ];
    Http::fake(['*' => Http::response(NativeApprovalWire::toolCalls($calls))]);
    $agent = (new NativeApprovalAgent)->forParticipant((object) ['id' => 102]);
    $agent->prompt('pause twice');

    try {
        $agent->prompt(Decisions::from(['fc_first' => Decision::approve()]));
        throw new LogicException('The incomplete native decision set did not fail.');
    } catch (ApprovalMismatchException $exception) {
        expect($exception->getMessage())->toContain('do not match')
            ->and($exception->pendingApprovals->pluck('id')->all())->toBe(['fc_first', 'fc_second']);
    }

    expect(NativeApprovalAgent::$effects)->toBe([]);
    Http::assertSentCount(1);
});

it('records trait approval results before the model call but characterizes the contract-only restriction', function () {
    $plain = new PlainConversationStore(new DatabaseConversationStore('testing'));
    app()->instance(ConversationStore::class, $plain);
    $requests = 0;
    Http::fake(function () use (&$requests, $plain) {
        $requests++;
        if ($requests === 1) {
            return Http::response(NativeApprovalWire::toolCalls([[
                'id' => 'fc_contract', 'call_id' => 'call_contract', 'name' => class_basename(NativeApprovalTool::class), 'arguments' => ['value' => 'contract-only'],
            ]]));
        }

        $steps = json_decode(DB::table('agent_conversation_messages')->where('role', 'assistant')->sole()->steps, true, flags: JSON_THROW_ON_ERROR);
        expect($plain->approvalWrites)->toBe(0)
            ->and($steps[0]['tool_calls'][0])->not->toHaveKey('result');

        return Http::response(NativeApprovalWire::final('contract continued'));
    });

    expect($plain)->not->toBeInstanceOf(ResolvesPendingApprovals::class)
        ->and($plain)->not->toBeInstanceOf(VerifiesConversationOwnership::class);
    $agent = (new ContractOnlyApprovalAgent)->forParticipant((object) ['id' => 103]);
    $agent->prompt('contract pause');
    $response = $agent->prompt(Decisions::from(['fc_contract' => Decision::approve()]));

    $row = DB::table('agent_conversation_messages')->where('role', 'assistant')->sole();
    $steps = json_decode($row->steps, true, flags: JSON_THROW_ON_ERROR);
    expect($response->text)->toBe('contract continued')
        ->and($plain->approvalWrites)->toBe(0)
        ->and($row->status)->toBe('completed')
        ->and($steps[0]['tool_calls'][0])->not->toHaveKey('result')
        ->and(NativeApprovalAgent::$effects)->toBe([
            ['tool' => 'approve', 'id' => 'fc_contract', 'value' => 'contract-only'],
        ]);
    Http::assertSentCount(2);
});

it('denies changed or deleted conversation ownership before transport and effects', function (string $mutation) {
    $store = new CapabilityPreservingConversationStore(new DatabaseConversationStore('testing'));
    app()->instance(ConversationStore::class, $store);
    Http::fake(['*' => Http::response(NativeApprovalWire::toolCalls([[
        'id' => 'fc_tenant', 'call_id' => 'call_tenant', 'name' => class_basename(NativeApprovalTool::class), 'arguments' => ['value' => 'tenant-secret'],
    ]]))]);
    $participant = (object) ['id' => 104];
    $agent = (new NativeApprovalAgent)->forParticipant($participant);
    $agent->prompt('tenant pause');
    $conversationId = $agent->currentConversation();

    if ($mutation === 'changed') {
        DB::table('agent_conversations')->where('id', $conversationId)->update(['participant_id' => 999]);
    } else {
        DB::table('agent_conversations')->where('id', $conversationId)->delete();
    }

    $continueForOwner = function () use ($store, $conversationId, $participant, $agent): void {
        if (! $store->conversationBelongsTo($conversationId, $participant::class, $participant->id)) {
            throw new LogicException('Conversation ownership changed before continuation.');
        }

        $agent->prompt(Decisions::from(['fc_tenant' => Decision::approve()]));
    };

    expect($continueForOwner)->toThrow(LogicException::class, 'ownership changed')
        ->and(NativeApprovalAgent::$effects)->toBe([]);
    Http::assertSentCount(1);
})->with(['changed', 'deleted']);

it('proves a committed-result retry is already resolved and reconstruction is only a divergent ordinary prompt', function () {
    $store = new CapabilityPreservingConversationStore(new DatabaseConversationStore('testing'));
    app()->instance(ConversationStore::class, $store);
    $requests = [];
    Http::fake(function (Request $request) use (&$requests) {
        $requests[] = ['url' => $request->url(), 'body' => $request->data()];
        $number = count($requests);

        return match ($number) {
            1 => Http::response(NativeApprovalWire::toolCalls([[
                'id' => 'fc_saved', 'call_id' => 'call_saved', 'name' => class_basename(NativeApprovalTool::class), 'arguments' => ['value' => 'saved-result'],
            ]])),
            2, 3 => Http::response(['error' => ['message' => 'rate limited']], 429),
            default => Http::response([
                'id' => 'chat-final',
                'model' => 'backup-model',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'reconstructed continuation'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 3],
            ]),
        };
    });
    $failedOver = [];
    $resolved = [];
    app('events')->listen(AgentFailedOver::class, function (AgentFailedOver $event) use (&$failedOver): void {
        $failedOver[] = $event;
    });
    app('events')->listen(ToolApprovalResolved::class, function (ToolApprovalResolved $event) use (&$resolved): void {
        $resolved[] = $event;
    });

    $agent = (new NativeApprovalAgent)->forParticipant((object) ['id' => 105]);
    $agent->prompt('save before provider failure');
    $conversationId = $agent->currentConversation();
    $decision = Decisions::from(['fc_saved' => Decision::approve()]);

    expect(fn () => $agent->prompt($decision, provider: [
        'openai' => 'gpt-4.1-mini',
        'openai-compatible' => 'backup-model',
    ]))->toThrow(RateLimitedException::class);
    expect($requests)->toHaveCount(2)
        ->and($store->approvalWrites)->toHaveCount(1)
        ->and(NativeApprovalAgent::$effects)->toBe([
            ['tool' => 'approve', 'id' => 'fc_saved', 'value' => 'saved-result'],
        ]);

    $savedSteps = json_decode(
        DB::table('agent_conversation_messages')->where('role', 'assistant')->sole()->steps,
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($savedSteps[0]['tool_calls'][0])->toMatchArray([
        'id' => 'fc_saved',
        'result' => 'approved:saved-result',
    ]);

    expect(fn () => $agent->prompt($decision))->toThrow(ApprovalMismatchException::class, 'already-resolved');
    expect($requests)->toHaveCount(2);

    $storedCount = DB::table('agent_conversation_messages')->count();
    $history = $store->getLatestConversationMessages($conversationId, 100)->all();
    $fallback = new ReconstructedHistoryAgent($history);
    $response = $fallback->prompt('Continue from the saved result.', provider: [
        'openai' => 'gpt-4.1-mini',
        'openai-compatible' => 'backup-model',
    ]);

    $fallbackInput = $requests[2]['body']['input'];
    expect(last($fallbackInput))->toBe([
        'role' => 'user',
        'content' => [['type' => 'input_text', 'text' => 'Continue from the saved result.']],
    ])->and(count(array_filter($fallbackInput, fn (mixed $item): bool => is_array($item) && ($item['role'] ?? null) === 'user')))->toBe(2)
        ->and($requests[2]['url'])->toContain('api.openai.com')
        ->and($requests[3]['url'])->toContain('backup.test')
        ->and($failedOver)->toHaveCount(1)
        ->and($resolved)->toBe([])
        ->and($response->text)->toBe('reconstructed continuation')
        ->and($response->conversationId)->toBeNull()
        ->and(DB::table('agent_conversation_messages')->count())->toBe($storedCount)
        ->and(ReconstructedHistoryAgent::DIVERGENCES)->toBe([
            'approval-validation',
            'provider-selection',
            'approval-events',
            'conversation-remembering',
            'model-failover',
        ]);
    Http::assertSentCount(4);
});
