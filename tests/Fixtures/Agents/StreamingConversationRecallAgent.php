<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Tools\Recall;
use Illuminate\Broadcasting\Channel;
use Illuminate\Container\Container;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * Final streaming agent that recalls the **Conversation**-scoped `finding`
 * mid-stream. The Recall tool resolves the conversation scope id from the active
 * run's `RunContext::conversationId()`, so the value this agent surfaces depends
 * on which run's frame is active — making it the right probe for asserting that
 * the conversation handle stays frame-isolated when two streamed runs are
 * interleaved in one process (the Octane fiber model).
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class StreamingConversationRecallAgent implements Agent
{
    public function instructions(): Stringable|string
    {
        return 'You read conversation-scoped memory before answering.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse(
            invocationId: 'streaming-conversation-recall',
            text: 'unused',
            usage: new Usage,
            meta: new Meta('fake', 'test'),
        );
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('streaming-conversation-recall-invocation', function (): \Generator {
            $timestamp = 1_710_000_000;
            $arguments = ['key' => 'finding', 'scope' => 'conversation'];

            // The Recall tool resolves the conversation scope id from the active
            // run frame's conversation handle — so a frame bleed would surface the
            // other run's conversation entry here.
            $result = Container::getInstance()->make(Recall::class)->handle(new Request($arguments));

            $toolCall = new ToolCallData(
                id: 'recall-call-1',
                name: 'recall',
                arguments: $arguments,
                resultId: 'recall-result-1',
            );
            $toolResult = new ToolResultData(
                id: 'recall-call-1',
                name: 'recall',
                arguments: $arguments,
                result: $result,
                resultId: 'recall-result-1',
            );

            yield new ToolCall('tool-call-recall', $toolCall, $timestamp);
            yield new ToolResult('tool-result-recall', $toolResult, true, null, $timestamp);
            yield new TextDelta('delta-recall', 'message-recall', $result, $timestamp);
            yield new TextEnd('text-end-recall', 'message-recall', $timestamp);
            yield new StreamEnd('stream-end-recall', 'stop', new Usage(promptTokens: 1, completionTokens: 1), $timestamp);
        }, new Meta('fake', 'test'));
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function queue(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Queueing is not supported in this test fixture.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcast(Decisions|string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Broadcasting is not supported in this test fixture.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcastNow(Decisions|string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Broadcasting is not supported in this test fixture.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcastOnQueue(Decisions|string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Broadcast queueing is not supported in this test fixture.');
    }
}
