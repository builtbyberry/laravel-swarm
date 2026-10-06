<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Bounded, versioned projection of the Laravel AI response that completed a
 * swarm step. Raw provider responses and Laravel AI objects are never retained.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class NativeStepResult implements Arrayable, JsonSerializable
{
    public const FORMAT_VERSION = 1;

    public const AVAILABLE = 'available';

    public const PARTIAL = 'partial';

    public const REDACTED = 'redacted';

    public const OMITTED = 'omitted';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, mixed>|null  $structured
     * @param  list<array<string, mixed>>  $generationSteps
     * @param  list<array<string, mixed>>  $tools
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $status = self::AVAILABLE,
        public ?array $structured = null,
        public ?string $reasoning = null,
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $invocationId = null,
        public ?string $conversationId = null,
        public ?string $userMessageId = null,
        public ?string $assistantMessageId = null,
        public array $generationSteps = [],
        public array $tools = [],
        public array $reasons = [],
        public int $formatVersion = self::FORMAT_VERSION,
    ) {}

    /** @param list<string> $reasons */
    public static function unavailable(array $reasons = ['legacy']): self
    {
        return new self(status: self::UNAVAILABLE, reasons: self::normalizeReasons($reasons));
    }

    public static function omitted(): self
    {
        return new self(status: self::OMITTED, reasons: ['capture_skip']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'format_version' => $this->formatVersion,
            'status' => $this->status,
            'reasons' => $this->reasons,
            'structured' => $this->structured,
            'reasoning' => $this->reasoning,
            'provider' => $this->provider,
            'model' => $this->model,
            'invocation_id' => $this->invocationId,
            'conversation_id' => $this->conversationId,
            'user_message_id' => $this->userMessageId,
            'assistant_message_id' => $this->assistantMessageId,
            'generation_steps' => $this->generationSteps,
            'tools' => $this->tools,
        ], static fn (mixed $value, string $key): bool => ! ($value === null || (($key === 'reasons' || $key === 'generation_steps' || $key === 'tools') && $value === [])), ARRAY_FILTER_USE_BOTH);
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (($payload['format_version'] ?? null) !== self::FORMAT_VERSION) {
            return self::unavailable(['unsupported_version']);
        }

        $status = $payload['status'] ?? null;
        if (! is_string($status) || ! in_array($status, [self::AVAILABLE, self::PARTIAL, self::REDACTED, self::OMITTED, self::UNAVAILABLE], true)) {
            return self::unavailable(['malformed']);
        }

        foreach (['structured', 'generation_steps', 'tools', 'reasons'] as $key) {
            if (array_key_exists($key, $payload) && ! is_array($payload[$key])) {
                return self::unavailable(['malformed']);
            }
        }

        foreach (['reasoning', 'provider', 'model', 'invocation_id', 'conversation_id', 'user_message_id', 'assistant_message_id'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && ! is_string($payload[$key])) {
                return self::unavailable(['malformed']);
            }
        }

        return new self(
            status: $status,
            structured: isset($payload['structured']) && is_array($payload['structured']) ? $payload['structured'] : null,
            reasoning: self::nullableString($payload['reasoning'] ?? null),
            provider: self::nullableString($payload['provider'] ?? null),
            model: self::nullableString($payload['model'] ?? null),
            invocationId: self::nullableString($payload['invocation_id'] ?? null),
            conversationId: self::nullableString($payload['conversation_id'] ?? null),
            userMessageId: self::nullableString($payload['user_message_id'] ?? null),
            assistantMessageId: self::nullableString($payload['assistant_message_id'] ?? null),
            generationSteps: self::listOfArrays($payload['generation_steps'] ?? []),
            tools: self::listOfArrays($payload['tools'] ?? []),
            reasons: self::normalizeReasons($payload['reasons'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @param array<mixed> $values
     * @return list<array<string, mixed>>
     */
    private static function listOfArrays(array $values): array
    {
        return array_values(array_filter($values, static fn (mixed $value): bool => is_array($value)));
    }

    /** @param array<mixed> $reasons
     * @return list<string>
     */
    private static function normalizeReasons(array $reasons): array
    {
        return array_values(array_unique(array_filter($reasons, static fn (mixed $reason): bool => is_string($reason) && $reason !== '')));
    }
}
