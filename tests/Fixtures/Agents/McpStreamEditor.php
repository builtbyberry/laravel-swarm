<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Illuminate\Broadcasting\Channel;
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
use RuntimeException;
use Stringable;

/**
 * A final agent that streams a single MCP-backed tool call whose result is a
 * deeply STRUCTURED (non-scalar) payload — the shape an MCP client tool returns
 * through `laravel/ai` 0.8. Swarm carries `ToolCall`/`ToolResult` as opaque
 * passthrough, so this proves an MCP tool result flows intact through capture,
 * the snapshot normalizer, and the streamed `swarm_tool_result` event.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class McpStreamEditor implements Agent
{
    /**
     * The structured MCP tool-result payload this fixture streams. Nested
     * arrays + mixed scalars mirror a real MCP `content` block list.
     *
     * @var array<string, mixed>
     */
    public const STRUCTURED_RESULT = [
        'content' => [
            ['type' => 'text', 'text' => 'Found 2 matching documents.'],
            ['type' => 'resource', 'uri' => 'mcp://docs/swarm', 'mimeType' => 'text/markdown'],
        ],
        'isError' => false,
        'metadata' => ['source' => 'mcp-docs-server', 'tookMs' => 42],
    ];

    public function instructions(): Stringable|string
    {
        return 'You are an MCP-backed stream editor.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse(
            invocationId: 'mcp-stream-editor',
            text: 'unused',
            usage: new Usage,
            meta: new Meta('fake', 'test'),
        );
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('mcp-stream-invocation', function (): \Generator {
            $timestamp = 1_710_000_000;
            $toolCall = new ToolCallData(
                id: 'mcp-call-1',
                name: 'docs.search',
                arguments: ['query' => 'swarm'],
                resultId: 'mcp-result-1',
            );
            $toolResult = new ToolResultData(
                id: 'mcp-call-1',
                name: 'docs.search',
                arguments: ['query' => 'swarm'],
                result: self::STRUCTURED_RESULT,
                resultId: 'mcp-result-1',
            );

            yield new TextDelta('delta-1', 'message-1', 'editor-out', $timestamp);
            yield new ToolCall('tool-call-1', $toolCall, $timestamp);
            yield new ToolResult('tool-result-1', $toolResult, true, null, $timestamp);
            yield new TextEnd('text-end-1', 'message-1', $timestamp);
            yield new StreamEnd('stream-end-1', 'stop', new Usage(promptTokens: 1, completionTokens: 1), $timestamp);
        }, new Meta('fake', 'test'));
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function queue(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Queueing is not supported in this test fixture.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcast(string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Broadcasting is not supported in this test fixture.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcastNow(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Broadcasting is not supported in this test fixture.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcastOnQueue(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Broadcast queueing is not supported in this test fixture.');
    }
}
