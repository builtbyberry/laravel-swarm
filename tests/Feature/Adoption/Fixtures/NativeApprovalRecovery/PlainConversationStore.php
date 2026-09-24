<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;
use Throwable;

class PlainConversationStore implements ConversationStore
{
    public int $approvalWrites = 0;

    public function __construct(private readonly ConversationStore $inner) {}

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
        return $this->inner->storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
    }

    /** @return Collection<int, Message> */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return $this->inner->getLatestConversationMessages($conversationId, $limit);
    }

    /** @param array<int, ToolResult> $toolResults */
    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
        $this->approvalWrites++;
        $this->inner->storeApprovalResults($conversationId, $toolResults);
    }
}
