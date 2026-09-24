<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeConversationUpgrade;

use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\ConversationAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\WorkflowAgent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Ai\Storage\StoredMessage;
use Mockery;
use RuntimeException;
use Throwable;

final class CustomStoreContract
{
    public static function verify(string $connection, string $conversations, string $messages): void
    {
        $store = new class(new DatabaseConversationStore($connection)) implements ConversationStore
        {
            public array $exceptions = [];

            public array $assistantReturns = [];

            public function __construct(private DatabaseConversationStore $inner) {}

            public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
            {
                return $this->inner->latestConversationId($participantType, $participantId, $agent);
            }

            public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
            {
                return $this->inner->storeConversation($participantType, $participantId, $title, $id);
            }

            public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
            {
                return $this->inner->storeUserMessage($conversationId, $participantType, $participantId, $agent, $message);
            }

            public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
            {
                $this->exceptions[] = $exception;
                $id = $this->inner->storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
                $this->assistantReturns[] = $id;

                return $id;
            }

            public function getLatestConversationMessages(string $conversationId, int $limit): Collection
            {
                return $this->inner->getLatestConversationMessages($conversationId, $limit);
            }

            public function storeApprovalResults(string $conversationId, array $toolResults): void
            {
                $this->inner->storeApprovalResults($conversationId, $toolResults);
            }
        };

        $database = DB::connection($connection);
        $conversationCount = $database->table($conversations)->count();
        $aId = 'c2000000-0000-4000-8000-000000000001';
        $bId = 'c2000000-0000-4000-8000-000000000002';
        $failureId = 'c2000000-0000-4000-8000-000000000003';
        $emptyId = 'c2000000-0000-4000-8000-000000000004';
        $pendingAId = 'c2000000-0000-4000-8000-000000000005';
        $pendingBId = 'c2000000-0000-4000-8000-000000000006';

        foreach ([$aId, $bId, $failureId, $emptyId, $pendingAId, $pendingBId] as $id) {
            expect($store->storeConversation('fixture-user', 17, 'custom-'.$id, $id))->toBe($id);
            $row = $database->table($conversations)->where('id', $id)->sole();
            expect($row->id)->toBe($id)
                ->and($row->participant_type)->toBe('fixture-user')
                ->and((int) $row->participant_id)->toBe(17)
                ->and($row->title)->toBe('custom-'.$id);
        }
        expect($database->table($conversations)->count())->toBe($conversationCount + 6);

        $store->storeUserMessage($aId, 'fixture-user', 17, ConversationAgent::class, new UserMessage('agent A'));
        $store->storeUserMessage($bId, 'fixture-user', 17, WorkflowAgent::class, new UserMessage('agent B'));
        expect($store->latestConversationId('fixture-user', 17, ConversationAgent::class))->toBe($aId)
            ->and($store->latestConversationId('fixture-user', 17, WorkflowAgent::class))->toBe($bId);

        $attachment = Document::fromString('attachment bytes — café', 'text/plain')->as('notes.txt');
        $user = new UserMessage('user content — café', [$attachment]);
        $userId = $store->storeUserMessage($aId, 'fixture-user', 17, ConversationAgent::class, $user);
        $userRow = $database->table($messages)->where('id', $userId)->sole();
        $storedUser = StoredMessage::fromArray((array) $userRow);
        expect($userRow->conversation_id)->toBe($aId)
            ->and($userRow->agent)->toBe(ConversationAgent::class)
            ->and($userRow->role)->toBe('user')
            ->and($userRow->content)->toBe($user->content)
            ->and(json_decode($userRow->attachments, true, flags: JSON_THROW_ON_ERROR))->toBe([$attachment->toArray()])
            ->and($storedUser->id)->toBe($userId)
            ->and($storedUser->content)->toBe($user->content)
            ->and($storedUser->attachments)->toBe([$attachment->toArray()])
            ->and($storedUser->status)->toBe(MessageStatus::Completed);
        $readUser = $store->getLatestConversationMessages($aId, 10)->firstWhere('content', $user->content);
        expect($readUser)->toBeInstanceOf(UserMessage::class)
            ->and($readUser->attachments)->toHaveCount(1);
        $readAttachment = $readUser->attachments->sole();
        expect($readAttachment)->toBeInstanceOf(Base64Document::class)
            ->and($readAttachment->content())->toBe('attachment bytes — café')
            ->and($readAttachment->name())->toBe('notes.txt')
            ->and($readAttachment->mimeType())->toBe('text/plain');

        $provider = Mockery::mock(TextProvider::class);
        $provider->shouldNotReceive('prompt', 'stream');
        $agent = new ConversationAgent;
        $prompt = new AgentPrompt($agent, 'store fixture', [], $provider, 'fixture-model');
        $exception = new RuntimeException('fixture failure');
        $failedId = $store->storeAssistantMessage(
            $failureId, 'fixture-user', 17, $prompt,
            new AgentResponse('failed-invocation', 'retained failed answer', new TextUsage, new Meta('fixture', 'fixture-model')),
            $exception,
        );
        expect($failedId)->not->toBeNull()
            ->and($store->exceptions)->toBe([$exception])
            ->and($store->assistantReturns)->toBe([$failedId])
            ->and($database->table($messages)->where('conversation_id', $failureId)->count())->toBe(1);
        $failedRow = $database->table($messages)->where('id', $failedId)->sole();
        $failed = StoredMessage::fromArray((array) $failedRow);
        expect($failedRow->status)->toBe('failed')
            ->and($failedRow->content)->toBe('retained failed answer')
            ->and(json_decode($failedRow->meta, true, flags: JSON_THROW_ON_ERROR)['error'])->toBe('fixture failure')
            ->and($failed->status)->toBe(MessageStatus::Failed)
            ->and($failed->meta['error'])->toBe('fixture failure');

        $normalId = $store->storeAssistantMessage(
            $failureId, 'fixture-user', 17, $prompt,
            new AgentResponse('normal-invocation', 'retained normal answer', new TextUsage, new Meta('fixture', 'fixture-model')),
            null,
        );
        expect($normalId)->not->toBeNull()
            ->and($normalId)->not->toBe($failedId)
            ->and($store->exceptions)->toBe([$exception, null])
            ->and($store->assistantReturns)->toBe([$failedId, $normalId])
            ->and($database->table($messages)->where('conversation_id', $failureId)->count())->toBe(2);
        $normalRow = $database->table($messages)->where('id', $normalId)->sole();
        $normal = StoredMessage::fromArray((array) $normalRow);
        expect($normalRow->status)->toBe('completed')
            ->and($normal->status)->toBe(MessageStatus::Completed)
            ->and($normal->content)->toBe('retained normal answer')
            ->and($normal->meta)->not->toHaveKey('error');

        $store->storeAssistantMessage(
            $emptyId, 'fixture-user', 17, $prompt,
            new AgentResponse('existing-invocation', 'existing completed answer', new TextUsage, new Meta),
            null,
        );
        $beforeEmpty = $database->table($messages)->where('conversation_id', $emptyId)->orderBy('id')->get()->all();
        $emptyPrompt = new AgentPrompt(
            $agent, '', [], $provider, 'fixture-model',
            approvalDecisions: Decisions::from(['already-recorded-call' => true]),
        );
        $emptyReturn = $store->storeAssistantMessage(
            $emptyId, 'fixture-user', 17, $emptyPrompt,
            new AgentResponse('empty-invocation', '', new TextUsage, new Meta),
            null,
        );
        expect($emptyReturn)->toBeNull()
            ->and(end($store->assistantReturns))->toBeNull()
            ->and($database->table($messages)->where('conversation_id', $emptyId)->orderBy('id')->get()->all())->toEqual($beforeEmpty)
            ->and($database->table($messages)->where('conversation_id', $emptyId)->count())->toBe(1);

        $pendingRows = [];
        foreach ([$pendingAId => 'A', $pendingBId => 'B'] as $conversationId => $label) {
            $arguments = ['value' => 'proposed-'.$label];
            $paused = new AgentResponse('paused-'.$label, '', new TextUsage, new Meta('fixture', 'fixture-model'));
            $paused->withSteps(collect([new Step(
                text: '',
                toolCalls: [new ToolCall('shared-call', 'FixtureTool', $arguments, resultId: 'provider-result')],
                toolResults: [],
                finishReason: FinishReason::ToolCalls,
                usage: new TextUsage,
                meta: new Meta('fixture', 'fixture-model'),
                reasoning: '',
                replayBlocks: [],
            )]));
            $paused->withPendingApprovals(collect([
                new PendingApproval('shared-call', 'FixtureTool', $arguments, 'fixture approval'),
            ]));
            $messageId = $store->storeAssistantMessage($conversationId, 'fixture-user', 17, $prompt, $paused, null);
            expect($messageId)->not->toBeNull();
            $row = $database->table($messages)->where('id', $messageId)->sole();
            $pendingRows[$label] = $row;
            $stored = StoredMessage::fromArray((array) $row);
            expect($row->status)->toBe('paused')
                ->and($stored->status)->toBe(MessageStatus::Paused)
                ->and($stored->toolCalls())->toHaveCount(1)
                ->and($stored->toolCalls()[0])->toMatchArray([
                    'id' => 'shared-call', 'arguments' => $arguments, 'approval_reason' => 'fixture approval',
                ])
                ->and($stored->toolCalls()[0])->not->toHaveKey('result')
                ->and($stored->toolResults())->toBe([]);
        }

        $store->storeApprovalResults($pendingAId, [
            new ToolResult('shared-call', 'FixtureTool', ['value' => 'executed-A'], 'result-A', resultId: 'provider-result'),
        ]);
        $answeredA = $database->table($messages)->where('id', $pendingRows['A']->id)->sole();
        expect(StoredMessage::fromArray((array) $answeredA)->toolResults())->toBe([[
            'id' => 'shared-call', 'name' => 'FixtureTool', 'arguments' => ['value' => 'executed-A'],
            'result_id' => 'provider-result', 'result' => 'result-A',
        ]])
            ->and($answeredA->status)->toBe('paused')
            ->and($database->table($messages)->where('id', $pendingRows['B']->id)->sole())->toEqual($pendingRows['B'])
            ->and(StoredMessage::fromArray((array) $database->table($messages)->where('id', $pendingRows['B']->id)->sole())->toolResults())->toBe([]);

        $store->storeApprovalResults($pendingBId, [
            new ToolResult('shared-call', 'FixtureTool', ['value' => 'executed-B'], 'result-B', resultId: 'provider-result'),
        ]);
        $answeredB = $database->table($messages)->where('id', $pendingRows['B']->id)->sole();
        expect(StoredMessage::fromArray((array) $answeredB)->toolResults())->toBe([[
            'id' => 'shared-call', 'name' => 'FixtureTool', 'arguments' => ['value' => 'executed-B'],
            'result_id' => 'provider-result', 'result' => 'result-B',
        ]])
            ->and($answeredB->status)->toBe('paused')
            ->and($database->table($messages)->where('id', $pendingRows['A']->id)->sole())->toEqual($answeredA);
        foreach ([$pendingAId => $pendingRows['A']->id, $pendingBId => $pendingRows['B']->id] as $conversationId => $messageId) {
            expect($database->table($messages)->where('conversation_id', $conversationId)->pluck('id')->all())->toBe([$messageId]);
        }
    }
}
