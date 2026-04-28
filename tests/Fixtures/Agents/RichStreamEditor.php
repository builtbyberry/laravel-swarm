<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Contracts\Agent;
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

class RichStreamEditor implements Agent
{
    public function instructions(): Stringable|string
    {
        return 'You are a rich stream editor.';
    }

    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse(
            invocationId: 'rich-stream-editor',
            text: 'unused',
            usage: new Usage,
            meta: new Meta('fake', 'test'),
        );
    }

    public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('rich-stream-invocation', function (): \Generator {
            $timestamp = 1_710_000_000;
            $toolCall = new ToolCallData(
                id: 'call-1',
                name: 'search_docs',
                arguments: ['query' => 'swarm'],
                resultId: 'result-1',
                reasoningId: 'reason-1',
                reasoningSummary: ['Need docs lookup'],
            );
            $toolResult = new ToolResultData(
                id: 'call-1',
                name: 'search_docs',
                arguments: ['query' => 'swarm'],
                result: ['matches' => 1],
                resultId: 'result-1',
            );

            yield new TextDelta('delta-1', 'message-1', 'editor-out', $timestamp);
            yield new ReasoningDelta('reasoning-delta-1', 'reason-1', 'thinking', $timestamp, ['Need docs lookup']);
            yield new ReasoningEnd('reasoning-end-1', 'reason-1', $timestamp, ['Need docs lookup']);
            yield new ToolCall('tool-call-1', $toolCall, $timestamp);
            yield new ToolResult('tool-result-1', $toolResult, true, null, $timestamp);
            yield new TextEnd('text-end-1', 'message-1', $timestamp);
            yield new StreamEnd('stream-end-1', 'stop', new Usage(promptTokens: 1, completionTokens: 1), $timestamp);
        }, new Meta('fake', 'test'));
    }

    public function queue(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Queueing is not supported in this test fixture.');
    }

    public function broadcast(string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Broadcasting is not supported in this test fixture.');
    }

    public function broadcastNow(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Broadcasting is not supported in this test fixture.');
    }

    public function broadcastOnQueue(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Broadcast queueing is not supported in this test fixture.');
    }
}
