<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;

// Derive participant identity from authenticated server-side state, never request input.
// The operation may inspect native history or invoke an application-owned agent.
return static function (
    ConversationStore $store,
    string $conversationId,
    string $participantType,
    string|int $participantId,
    Closure $operation,
): mixed {
    if ($participantType === '' || (string) $participantId === ''
        || ! $store instanceof VerifiesConversationOwnership
        || ! $store->conversationBelongsTo($conversationId, $participantType, $participantId)) {
        throw new AuthorizationException('Conversation access denied.');
    }

    return $operation($store, $conversationId);
};
