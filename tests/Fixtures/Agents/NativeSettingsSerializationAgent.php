<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Closure;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use RuntimeException;

/**
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
final class NativeSettingsSerializationAgent extends SerializationBoundaryAgent
{
    /** @var list<object> */
    private array $runtimeTools = [];

    /** @var list<Message> */
    private array $adHocMessages = [];

    /** @param iterable<int, object>|Closure $tools */
    public function withTools(Closure|iterable $tools): static
    {
        if ($tools instanceof Closure) {
            throw new RuntimeException('Process proof requires concrete reconstructed tools.');
        }

        $this->runtimeTools = [...$tools];

        return $this;
    }

    /** @param iterable<int, Message> $messages */
    public function withMessages(iterable $messages): static
    {
        $this->adHocMessages = [...$messages];

        return $this;
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        $message = $this->adHocMessages[0] ?? null;
        $tool = $this->runtimeTools[0] ?? null;
        $providerName = $provider instanceof Lab ? $provider->value : (is_string($provider) ? $provider : 'none');
        $promptText = $prompt instanceof UserMessage ? $prompt->content : (is_string($prompt) ? $prompt : 'decisions');

        return new AgentResponse(
            invocationId: 'native-settings-serialization-agent',
            text: implode(':', [
                'native-settings',
                $promptText,
                $message instanceof Message ? (string) $message->content : 'no-history',
                is_object($tool) && property_exists($tool, 'tenant') ? (string) $tool->tenant : 'no-tool',
                $providerName,
                $model ?? 'no-model',
                (string) ($timeout ?? 0),
            ]),
            usage: new TextUsage,
            meta: new Meta('fake', 'test'),
        );
    }
}
