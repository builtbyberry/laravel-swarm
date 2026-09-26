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
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
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
        $text = $this->responseText($prompt, $provider, $model, $timeout);

        return new AgentResponse(
            invocationId: 'native-settings-serialization-agent',
            text: $text,
            usage: new TextUsage,
            meta: new Meta('fake', 'test'),
        );
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        $text = $this->responseText($prompt, $provider, $model, $timeout);

        return new StreamableAgentResponse('native-settings-serialization-agent', function () use ($text): \Generator {
            yield (new TextDelta('native-settings-delta', 'native-settings-message', $text, 1710000001))
                ->withInvocationId('native-settings-serialization-agent');
            yield (new StreamEnd('native-settings-end', 'stop', new TextUsage, 1710000002))
                ->withInvocationId('native-settings-serialization-agent');
        }, new Meta('fake', 'test'));
    }

    /** @param  LaravelAiAgentProvider  $provider */
    private function responseText(
        AgentInput|UserMessage|Decisions|string $prompt,
        Lab|array|string|null $provider,
        ?string $model,
        ?int $timeout,
    ): string {
        $message = $this->adHocMessages[0] ?? null;
        $tool = $this->runtimeTools[0] ?? null;
        $providerName = $provider instanceof Lab ? $provider->value : (is_string($provider) ? $provider : 'none');
        $attachmentContent = $prompt instanceof UserMessage
            ? $prompt->attachments->map(
                static fn ($attachment): string => is_object($attachment) && method_exists($attachment, 'content')
                    ? (string) $attachment->content()
                    : '',
            )->filter()->implode('|')
            : '';
        $promptText = $prompt instanceof UserMessage ? $prompt->content : (is_string($prompt) ? $prompt : 'decisions');

        return implode(':', array_values(array_filter([
            'native-settings',
            $promptText,
            $attachmentContent === '' ? null : $attachmentContent,
            $message instanceof Message ? (string) $message->content : 'no-history',
            is_object($tool) && property_exists($tool, 'tenant') ? (string) $tool->tenant : 'no-tool',
            $providerName,
            $model ?? 'no-model',
            (string) ($timeout ?? 0),
        ], static fn (?string $value): bool => $value !== null)));
    }
}
