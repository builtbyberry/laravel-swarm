<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;

final readonly class NativeAgentInvocation
{
    /**
     * @param  Lab|array<string, mixed>|string|null  $provider
     * @param  list<NativeAgentToolReference>  $tools
     * @param  list<Message>  $messages
     */
    public function __construct(
        public string|UserMessage $prompt,
        public Lab|array|string|null $provider = null,
        public ?string $model = null,
        public ?int $timeout = null,
        public array $tools = [],
        public array $messages = [],
        public ?NativeAgentConversation $conversation = null,
        public ?string $configurationId = null,
        public bool $clearMessages = false,
    ) {}
}
