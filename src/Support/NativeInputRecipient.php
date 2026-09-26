<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;

final readonly class NativeInputRecipient
{
    /**
     * @param  list<int>|null  $attachments
     * @param  Lab|array<string, mixed>|string|null  $provider
     * @param  list<NativeAgentToolReference|NativeAgentToolFactoryReference>  $tools
     * @param  list<Message>  $messages
     * @param  array<int, array<int, array{sha256?: string, owned?: bool, mime?: string}>>  $messageAttachmentMetadata
     */
    public function __construct(
        public string $recipient,
        public string $textSource = 'topology',
        public ?array $attachments = null,
        public Lab|array|string|null $provider = null,
        public ?string $model = null,
        public ?int $timeout = null,
        public array $tools = [],
        public array $messages = [],
        public ?NativeAgentConversation $conversation = null,
        public ?string $configurationId = null,
        protected array $messageAttachmentMetadata = [],
        public bool $toolsConfigured = false,
        public bool $messagesConfigured = false,
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

        foreach ($tools as $tool) {
            if (! $tool instanceof NativeAgentToolReference && ! $tool instanceof NativeAgentToolFactoryReference) {
                throw new SwarmException('Native agent tools must be reconstructible tool references or registered factory references. Closures and live container services cannot cross worker boundaries.');
            }
        }

        foreach ($messages as $message) {
            if (! $message instanceof Message) {
                throw new SwarmException('Native agent message overrides must be Laravel AI Message instances or values accepted by Message::tryFrom().');
            }
        }

        if ($messages !== [] && $conversation !== null) {
            throw new SwarmException('Laravel AI native conversations cannot be combined with one-shot withMessages history for the same recipient.');
        }

        if ($configurationId !== null && trim($configurationId) === '') {
            throw new SwarmException('Native agent configuration IDs must be non-empty strings.');
        }
    }

    /** @param Lab|array<string, mixed>|string|null $provider */
    public function withInvocation(Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): self
    {
        return new self($this->recipient, $this->textSource, $this->attachments, $provider, $model, $timeout, $this->tools, $this->messages, $this->conversation, $this->configurationId, $this->messageAttachmentMetadata, $this->toolsConfigured, $this->messagesConfigured);
    }

    /** @param list<NativeAgentToolReference|NativeAgentToolFactoryReference> $tools */
    public function withTools(array $tools): self
    {
        return new self($this->recipient, $this->textSource, $this->attachments, $this->provider, $this->model, $this->timeout, array_values($tools), $this->messages, $this->conversation, $this->configurationId, $this->messageAttachmentMetadata, true, $this->messagesConfigured);
    }

    /** @param iterable<int, mixed> $messages */
    public function withMessages(iterable $messages): self
    {
        $normalized = [];
        foreach ($messages as $message) {
            $normalized[] = Message::tryFrom($message);
        }

        return new self($this->recipient, $this->textSource, $this->attachments, $this->provider, $this->model, $this->timeout, $this->tools, $normalized, $this->conversation, $this->configurationId, [], $this->toolsConfigured, true);
    }

    public function withConversation(NativeAgentConversation $conversation): self
    {
        return new self($this->recipient, $this->textSource, $this->attachments, $this->provider, $this->model, $this->timeout, $this->tools, $this->messages, $conversation, $this->configurationId, $this->messageAttachmentMetadata, $this->toolsConfigured, $this->messagesConfigured);
    }

    /**
     * @param  list<NativeAgentToolReference|NativeAgentToolFactoryReference>  $tools
     * @param  list<Message>|null  $messages
     * @param  array<int, array<int, array{sha256?: string, owned?: bool, mime?: string}>>|null  $messageAttachmentMetadata
     *
     * @internal Freeze a factory-expanded recipient for sealed transport.
     */
    public function withResolvedSettings(
        array $tools,
        ?string $configurationId = null,
        ?array $messages = null,
        ?array $messageAttachmentMetadata = null,
    ): self {
        return new self(
            $this->recipient,
            $this->textSource,
            $this->attachments,
            $this->provider,
            $this->model,
            $this->timeout,
            array_values($tools),
            array_values($messages ?? $this->messages),
            $this->conversation,
            $configurationId ?? $this->settingsId(),
            $messageAttachmentMetadata ?? $this->messageAttachmentMetadata,
            $this->toolsConfigured,
            $this->messagesConfigured,
        );
    }

    public function hasNativeSettings(): bool
    {
        return $this->toolsConfigured || $this->messagesConfigured || $this->conversation !== null;
    }

    public function hasExplicitConfiguration(): bool
    {
        return $this->hasNativeSettings()
            || $this->provider !== null
            || $this->model !== null
            || $this->timeout !== null;
    }

    /** @param list<int>|null $attachments */
    public function withInputRouting(string $textSource, ?array $attachments): self
    {
        return new self(
            $this->recipient,
            $textSource,
            $attachments,
            $this->provider,
            $this->model,
            $this->timeout,
            $this->tools,
            $this->messages,
            $this->conversation,
            $this->configurationId,
            $this->messageAttachmentMetadata,
            $this->toolsConfigured,
            $this->messagesConfigured,
        );
    }

    public function withConfigurationFrom(self $configuration): self
    {
        $tools = $configuration->toolsConfigured ? $configuration->tools : $this->tools;
        $toolsConfigured = $configuration->toolsConfigured || $this->toolsConfigured;

        if ($configuration->messagesConfigured) {
            $messages = $configuration->messages;
            $messageAttachmentMetadata = $configuration->messageAttachmentMetadata;
            $messagesConfigured = true;
            $conversation = $configuration->conversation;
        } elseif ($configuration->conversation !== null) {
            $messages = [];
            $messageAttachmentMetadata = [];
            $messagesConfigured = false;
            $conversation = $configuration->conversation;
        } else {
            $messages = $this->messages;
            $messageAttachmentMetadata = $this->messageAttachmentMetadata;
            $messagesConfigured = $this->messagesConfigured;
            $conversation = $this->conversation;
        }

        return new self(
            $this->recipient,
            $this->textSource,
            $this->attachments,
            $configuration->provider ?? $this->provider,
            $configuration->model ?? $this->model,
            $configuration->timeout ?? $this->timeout,
            $tools,
            $messages,
            $conversation,
            $configuration->hasNativeSettings() ? $configuration->configurationId : $this->configurationId,
            $messageAttachmentMetadata,
            $toolsConfigured,
            $messagesConfigured,
        );
    }

    public function settingsId(): string
    {
        return $this->configurationId ?? 'recipient:'.$this->recipient;
    }

    /** @internal */
    public function ownsMessageAttachment(int $messageIndex, int $attachmentIndex): bool
    {
        return ($this->messageAttachmentMetadata[$messageIndex][$attachmentIndex]['owned'] ?? false) === true;
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
            'configuration_id' => $this->hasNativeSettings() ? $this->settingsId() : null,
            'tools_configured' => $this->toolsConfigured,
            'messages_configured' => $this->messagesConfigured,
            'tools' => array_map(static function (NativeAgentToolReference|NativeAgentToolFactoryReference $tool): array {
                if ($tool instanceof NativeAgentToolFactoryReference) {
                    throw new SwarmException('Native agent tool factories must be expanded before the operational envelope is sealed.');
                }

                return $tool->toArray();
            }, $this->tools),
            'messages' => array_map(
                fn (Message $message, int $index): array => NativeMessageCodec::encode(
                    $message,
                    $this->messageAttachmentMetadata[$index] ?? [],
                ),
                $this->messages,
                array_keys($this->messages),
            ),
            'conversation' => $this->conversation?->toArray(),
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

        $tools = $payload['tools'] ?? [];
        $messages = $payload['messages'] ?? [];
        $conversation = $payload['conversation'] ?? null;
        $configurationId = $payload['configuration_id'] ?? null;
        $toolsConfigured = $payload['tools_configured'] ?? ($tools !== []);
        $messagesConfigured = $payload['messages_configured'] ?? ($messages !== []);
        if (! is_array($tools) || ! is_array($messages) || ($conversation !== null && ! is_array($conversation))
            || ($configurationId !== null && ! is_string($configurationId))
            || ! is_bool($toolsConfigured) || ! is_bool($messagesConfigured)) {
            throw new SwarmException('Native input recipient descriptor contains invalid native agent settings.');
        }

        return new self(
            recipient: $payload['recipient'],
            textSource: $payload['text_source'],
            attachments: $payload['attachments'],
            provider: $payload['provider'],
            model: $payload['model'],
            timeout: $payload['timeout'],
            tools: array_map(static function (mixed $tool): NativeAgentToolReference {
                if (! is_array($tool)) {
                    throw new SwarmException('Native agent tool descriptor is invalid.');
                }

                return NativeAgentToolReference::fromArray($tool);
            }, array_values($tools)),
            messages: array_map(static function (mixed $message): Message {
                if (! is_array($message)) {
                    throw new SwarmException('Native agent message descriptor is invalid.');
                }

                return NativeMessageCodec::decode($message);
            }, array_values($messages)),
            conversation: is_array($conversation) ? NativeAgentConversation::fromArray($conversation) : null,
            configurationId: $configurationId,
            messageAttachmentMetadata: array_map(
                static fn (mixed $message): array => is_array($message)
                    ? NativeMessageCodec::attachmentMetadata($message)
                    : [],
                array_values($messages),
            ),
            toolsConfigured: $toolsConfigured,
            messagesConfigured: $messagesConfigured,
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
