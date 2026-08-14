<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use RuntimeException;
use Stringable;

/**
 * Streams a laravel/ai 0.8 OpenAI ZDR (zero-data-retention) shape: reasoning
 * events whose `summary` is null (the provider retains nothing) and a tool call
 * carrying an opaque `reasoningEncryptedContent` blob. Used to prove the runner
 * capture path tolerates the ZDR shape without crashing (F4, issue #255) and
 * never leaks the encrypted blob into swarm's event/snapshot contract.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class ZdrStreamEditor implements Agent
{
    public function instructions(): Stringable|string
    {
        return 'You are a ZDR stream editor.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse(
            invocationId: 'zdr-stream-editor',
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
        return new StreamableAgentResponse('zdr-stream-invocation', function (): \Generator {
            $timestamp = 1_710_000_500;
            $toolCall = new ToolCallData(
                id: 'zdr-call-1',
                name: 'search_docs',
                arguments: ['query' => 'zdr'],
                resultId: 'zdr-result-1',
                reasoningId: 'zdr-reason-1',
                reasoningSummary: null,
                reasoningEncryptedContent: 'OPAQUE_ENCRYPTED_REASONING',
            );
            $toolResult = new ToolResultData(
                id: 'zdr-call-1',
                name: 'search_docs',
                arguments: ['query' => 'zdr'],
                result: ['matches' => 1],
                resultId: 'zdr-result-1',
            );

            yield new TextDelta('zdr-delta-1', 'zdr-message-1', 'zdr-out', $timestamp);
            // ZDR: a non-null delta but a null summary — the provider retains no
            // human-readable reasoning. captureReasoningSummary(null) must not crash.
            yield new ReasoningDelta('zdr-reasoning-delta-1', 'zdr-reason-1', 'thinking', $timestamp, null);
            yield new ReasoningEnd('zdr-reasoning-end-1', 'zdr-reason-1', $timestamp, null);
            yield new ToolCall('zdr-tool-call-1', $toolCall, $timestamp);
            yield new ToolResult('zdr-tool-result-1', $toolResult, true, null, $timestamp);
            yield new TextEnd('zdr-text-end-1', 'zdr-message-1', $timestamp);
            yield new StreamEnd('zdr-stream-end-1', 'stop', new Usage(promptTokens: 1, completionTokens: 1), $timestamp);
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
