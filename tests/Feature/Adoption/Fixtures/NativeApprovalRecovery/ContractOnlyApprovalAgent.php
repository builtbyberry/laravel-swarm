<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Promptable;

#[Provider('openai')]
#[Model('gpt-4.1-mini')]
class ContractOnlyApprovalAgent implements Agent, HasTools, RemembersConversations
{
    use Promptable;

    protected ?string $conversationId = null;

    protected ?object $participant = null;

    public function instructions(): string
    {
        return 'Exercise the contract-only conversation path.';
    }

    public function tools(): iterable
    {
        return [new NativeApprovalTool];
    }

    public function forParticipant(object $participant): static
    {
        $this->conversationId = null;
        $this->participant = $participant;

        return $this;
    }

    public function forUser(object $user): static
    {
        return $this->forParticipant($user);
    }

    public function continue(string $conversationId, ?object $as = null): static
    {
        $this->conversationId = $conversationId;
        $this->participant = $as;

        return $this;
    }

    public function continueOrStart(?string $conversationId, object $as): static
    {
        return $conversationId === null ? $this->forParticipant($as) : $this->continue($conversationId, $as);
    }

    public function continueLastConversation(object $as): static
    {
        $this->participant = $as;
        $this->conversationId = resolve(ConversationStore::class)->latestConversationId(
            Conversation::participantType($as),
            Conversation::participantKey($as),
            static::class,
        );

        return $this;
    }

    public function messages(): iterable
    {
        return $this->conversationId === null
            ? []
            : resolve(ConversationStore::class)->getLatestConversationMessages($this->conversationId, 100)->all();
    }

    public function currentConversation(): ?string
    {
        return $this->conversationId;
    }

    public function hasConversationParticipant(): bool
    {
        return $this->participant !== null;
    }

    public function conversationParticipant(): ?object
    {
        return $this->participant;
    }
}
