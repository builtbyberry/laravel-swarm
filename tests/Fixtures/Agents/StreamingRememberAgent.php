<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Tools\Remember;
use Illuminate\Broadcasting\Channel;
use Illuminate\Container\Container;
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
 * Final streaming agent that actually invokes the real {@see Remember} tool
 * mid-stream, then emits the resulting `ToolCall` / `ToolResult` events exactly
 * as `laravel/ai` would once a tool returns. Used to prove the Swarm memory
 * tools work end-to-end inside `$agent->stream(...)`: the write side-effect
 * lands in memory, the tool result flows through the stream as a standard
 * tool event, and the runner pairs the call + result into the step snapshot.
 *
 * The runner publishes the active run before invoking `stream()`, so the tool
 * resolves its scope id from the ambient run exactly as it does under
 * `prompt()`.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class StreamingRememberAgent implements Agent
{
    public function instructions(): Stringable|string
    {
        return 'You save findings to shared memory while you work.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse(
            invocationId: 'streaming-remember',
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
        return new StreamableAgentResponse('streaming-remember-invocation', function (): \Generator {
            $timestamp = 1_710_000_000;
            $arguments = ['key' => 'finding', 'value' => 'streamed-answer', 'scope' => 'run'];

            // Invoke the real Remember tool: the write lands in memory now, and
            // its return string becomes the tool result that flows downstream.
            $result = Container::getInstance()->make(Remember::class)->handle(new Request($arguments));

            $toolCall = new ToolCallData(
                id: 'remember-call-1',
                name: 'remember',
                arguments: $arguments,
                resultId: 'remember-result-1',
            );
            $toolResult = new ToolResultData(
                id: 'remember-call-1',
                name: 'remember',
                arguments: $arguments,
                result: $result,
                resultId: 'remember-result-1',
            );

            yield new ToolCall('tool-call-remember', $toolCall, $timestamp);
            yield new ToolResult('tool-result-remember', $toolResult, true, null, $timestamp);
            yield new TextDelta('delta-remember', 'message-remember', 'saved', $timestamp);
            yield new TextEnd('text-end-remember', 'message-remember', $timestamp);
            yield new StreamEnd('stream-end-remember', 'stop', new Usage(promptTokens: 1, completionTokens: 1), $timestamp);
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
