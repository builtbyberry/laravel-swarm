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
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use RuntimeException;
use Stringable;

/**
 * A final agent that streams an MCP-backed tool call whose result carries a
 * value JSON cannot represent — an invalid UTF-8 byte sequence, as a binary-ish
 * MCP tool might return. This drives the degrade-safe tool-result boundary: the
 * run must complete without a `JsonException` escaping a runner, with the
 * unencodable result substituted by a typed placeholder at the snapshot and
 * `swarm_tool_result` boundaries.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class UnencodableMcpStreamEditor implements Agent
{
    public const TOOL_NAME = 'docs.binary_fetch';

    /**
     * An invalid UTF-8 byte sequence — `json_encode(..., JSON_THROW_ON_ERROR)`
     * rejects this, mirroring a binary-ish MCP tool result that cannot round
     * trip through a JSON column.
     *
     * @return array<string, mixed>
     */
    public static function unencodableResult(): array
    {
        return ['blob' => "\xB1\x31\xFE"];
    }

    public function instructions(): Stringable|string
    {
        return 'You are an MCP-backed stream editor returning binary content.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse(
            invocationId: 'unencodable-mcp-stream-editor',
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
        return new StreamableAgentResponse('unencodable-mcp-stream-invocation', function (): \Generator {
            $timestamp = 1_710_000_000;
            $toolCall = new ToolCallData(
                id: 'mcp-call-1',
                name: self::TOOL_NAME,
                arguments: ['path' => '/blob'],
                resultId: 'mcp-result-1',
            );
            $toolResult = new ToolResultData(
                id: 'mcp-call-1',
                name: self::TOOL_NAME,
                arguments: ['path' => '/blob'],
                result: self::unencodableResult(),
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
