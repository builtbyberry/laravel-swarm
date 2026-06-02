<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Concerns\RemembersRunContext;
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * A Conversational agent that uses the RemembersRunContext trait. On each
 * invocation it captures the Message[] the trait renders (the propagation-policy
 * view of the active run) into a static collector, then delegates to the faked
 * gateway, so tests can assert on exactly what the agent would have sent.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class RememberingWriter implements Agent, Conversational
{
    use Promptable {
        prompt as protected promptViaGateway;
        stream as protected streamViaGateway;
    }
    use RemembersRunContext;

    /** @var array<int, array<int, Message>> */
    public static array $capturedMessages = [];

    public static function resetCaptured(): void
    {
        self::$capturedMessages = [];
    }

    public function instructions(): string
    {
        return 'You are a writer who remembers the run.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        $this->capture();

        return $this->promptViaGateway($prompt, $attachments, $provider, $model, $timeout);
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $this->capture();

        return $this->streamViaGateway($prompt, $attachments, $provider, $model, $timeout);
    }

    private function capture(): void
    {
        $messages = $this->messages();

        self::$capturedMessages[] = is_array($messages) ? $messages : iterator_to_array($messages);
    }
}
