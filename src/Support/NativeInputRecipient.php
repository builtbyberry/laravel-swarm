<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Laravel\Ai\Enums\Lab;

final readonly class NativeInputRecipient
{
    /**
     * @param  list<int>|null  $attachments
     * @param  Lab|array<string, mixed>|string|null  $provider
     */
    public function __construct(
        public string $recipient,
        public string $textSource = 'topology',
        public ?array $attachments = null,
        public Lab|array|string|null $provider = null,
        public ?string $model = null,
        public ?int $timeout = null,
    ) {
        if (! in_array($textSource, ['topology', 'original'], true)) {
            throw new SwarmException('Native input textSource must be [topology] or [original].');
        }

        if ($attachments !== null && (! array_is_list($attachments)
            || array_filter($attachments, static fn (mixed $index): bool => ! is_int($index) || $index < 0) !== [])) {
            throw new SwarmException('Native input attachment indexes must be non-negative integers.');
        }

        if (trim($recipient) === '') {
            throw new SwarmException('Native input recipient identity cannot be empty.');
        }

        self::assertProvider($provider);

        if ($model !== null && trim($model) === '') {
            throw new SwarmException('Native input provider model cannot be empty.');
        }

        if ($timeout !== null && $timeout <= 0) {
            throw new SwarmException('Native input provider timeout must be a positive integer.');
        }
    }

    /** @param Lab|array<string, mixed>|string|null $provider */
    public function withInvocation(Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): self
    {
        return new self($this->recipient, $this->textSource, $this->attachments, $provider, $model, $timeout);
    }

    /** @param list<int>|null $attachments */
    public static function sequential(int $slot, string $textSource = 'topology', ?array $attachments = null): self
    {
        self::assertSlot($slot);

        return new self("sequential:{$slot}", $textSource, $attachments);
    }

    /** @param list<int>|null $attachments */
    public static function parallel(int $slot, string $textSource = 'topology', ?array $attachments = null): self
    {
        self::assertSlot($slot);

        return new self("parallel:{$slot}", $textSource, $attachments);
    }

    /** @param list<int>|null $attachments */
    public static function generatedCoordinator(string $textSource = 'topology', ?array $attachments = null): self
    {
        return new self('generated:coordinator', $textSource, $attachments);
    }

    /** @param list<int>|null $attachments */
    public static function generatedNode(string $nodeId, string $textSource = 'topology', ?array $attachments = null): self
    {
        self::assertNodeId($nodeId);

        if (in_array($nodeId, ['coordinator', 'finish', 'parallel'], true)) {
            throw new SwarmException("Native input generated node id [{$nodeId}] is reserved for route control.");
        }

        return new self("generated:{$nodeId}", $textSource, $attachments);
    }

    /** @param list<int>|null $attachments */
    public static function staticNode(string $nodeId, string $textSource = 'topology', ?array $attachments = null): self
    {
        self::assertNodeId($nodeId);

        return new self("static:{$nodeId}", $textSource, $attachments);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'recipient' => $this->recipient,
            'text_source' => $this->textSource,
            'attachments' => $this->attachments,
            'provider' => $this->provider instanceof Lab ? $this->provider->value : $this->provider,
            'model' => $this->model,
            'timeout' => $this->timeout,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        foreach (['recipient', 'text_source', 'attachments', 'provider', 'model', 'timeout'] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new SwarmException("Native input recipient descriptor is missing [{$field}].");
            }
        }

        if (! is_string($payload['recipient']) || ! is_string($payload['text_source'])) {
            throw new SwarmException('Native input recipient descriptor contains an invalid recipient or text source.');
        }

        if ($payload['attachments'] !== null && ! is_array($payload['attachments'])) {
            throw new SwarmException('Native input recipient descriptor contains an invalid attachment selection.');
        }
        if (is_array($payload['attachments']) && (! array_is_list($payload['attachments'])
            || array_filter($payload['attachments'], static fn (mixed $index): bool => ! is_int($index) || $index < 0) !== [])) {
            throw new SwarmException('Native input recipient descriptor contains an invalid attachment selection.');
        }

        if ($payload['provider'] !== null && ! is_string($payload['provider']) && ! is_array($payload['provider'])) {
            throw new SwarmException('Native input recipient descriptor contains an invalid provider selection.');
        }

        if ($payload['model'] !== null && ! is_string($payload['model'])) {
            throw new SwarmException('Native input recipient descriptor contains an invalid model selection.');
        }

        if ($payload['timeout'] !== null && ! is_int($payload['timeout'])) {
            throw new SwarmException('Native input recipient descriptor contains an invalid timeout.');
        }

        return new self(
            recipient: $payload['recipient'],
            textSource: $payload['text_source'],
            attachments: $payload['attachments'],
            provider: $payload['provider'],
            model: $payload['model'],
            timeout: $payload['timeout'],
        );
    }

    /** @param Lab|array<string|int, mixed>|string|null $provider */
    protected static function assertProvider(Lab|array|string|null $provider): void
    {
        if (is_string($provider)) {
            if (trim($provider) === '') {
                throw new SwarmException('Native input provider selection cannot be empty.');
            }

            return;
        }

        if (! is_array($provider)) {
            return;
        }

        if ($provider === []) {
            throw new SwarmException('Native input provider failover selection cannot be empty.');
        }

        foreach ($provider as $key => $value) {
            if (is_int($key)) {
                if ((! is_string($value) && ! $value instanceof Lab)
                    || (is_string($value) && trim($value) === '')) {
                    throw new SwarmException('Native input provider failover entries must be non-empty provider names.');
                }

                continue;
            }

            if (trim($key) === '' || ($value !== null && (! is_string($value) || trim($value) === ''))) {
                throw new SwarmException('Native input provider failover map must use provider names with optional model names.');
            }
        }
    }

    protected static function assertSlot(int $slot): void
    {
        if ($slot < 0) {
            throw new SwarmException('Native input recipient slots must be non-negative integers.');
        }
    }

    protected static function assertNodeId(string $nodeId): void
    {
        if (trim($nodeId) === '') {
            throw new SwarmException('Native input recipient node ids cannot be empty.');
        }
    }
}
