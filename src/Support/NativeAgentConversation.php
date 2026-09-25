<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Database\Eloquent\Model;

final class NativeAgentConversation
{
    protected function __construct(
        public readonly ?string $conversationId,
        protected ?object $participant,
        public readonly ?string $participantClass = null,
        public readonly string|int|null $participantKey = null,
    ) {
        if ($conversationId !== null && trim($conversationId) === '') {
            throw new SwarmException('Native conversation IDs must be non-empty strings.');
        }
    }

    public static function start(object $participant): self
    {
        return new self(null, $participant);
    }

    public static function continue(string $conversationId, object $participant): self
    {
        return new self($conversationId, $participant);
    }

    public function participant(): object
    {
        if ($this->participant !== null) {
            return $this->participant;
        }

        $class = $this->participantClass;
        if ($class === null || ! is_subclass_of($class, Model::class)) {
            throw new SwarmException('Reconstructed native conversations require an Eloquent participant reference.');
        }

        $participant = $class::query()->find($this->participantKey);
        if (! $participant instanceof Model) {
            throw new SwarmException("Native conversation participant [{$class}:{$this->participantKey}] no longer exists.");
        }

        return $this->participant = $participant;
    }

    /** @return array{conversation_id: string|null, participant_class: string, participant_key: string|int} */
    public function toArray(): array
    {
        $participant = $this->participant();
        if (! $participant instanceof Model) {
            throw new SwarmException('Recoverable native conversations require an Eloquent participant. Use an existing conversation ID and an Eloquent participant, or keep the run request-local.');
        }

        return [
            'conversation_id' => $this->conversationId,
            'participant_class' => $participant::class,
            'participant_key' => $participant->getKey(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $id = $payload['conversation_id'] ?? null;
        $class = $payload['participant_class'] ?? null;
        $key = $payload['participant_key'] ?? null;

        if (($id !== null && ! is_string($id)) || ! is_string($class) || $class === '' || (! is_string($key) && ! is_int($key))) {
            throw new SwarmException('Native conversation descriptor is invalid.');
        }

        return new self($id, null, $class, $key);
    }
}
