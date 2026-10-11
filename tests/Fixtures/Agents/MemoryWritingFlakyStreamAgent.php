<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Tools\Remember;
use Generator;
use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * Durable streaming retry fixture whose first attempt crashes before writing
 * and whose successful retry writes through the real Remember tool.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class MemoryWritingFlakyStreamAgent implements Agent
{
    public static int $attempts = 0;

    public static bool $failAfterWrite = false;

    public static int $writeAttempts = 0;

    public static function reset(): void
    {
        self::$attempts = 0;
        self::$failAfterWrite = false;
        self::$writeAttempts = 0;
    }

    public function instructions(): Stringable|string
    {
        return 'Write retry memory after recovering from a stream crash.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        throw new RuntimeException('Blocking is not supported in this test fixture.');
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $attempt = ++self::$attempts;

        return new StreamableAgentResponse('memory-write-stream-'.$attempt, function () use ($attempt): Generator {
            if ($attempt === 1) {
                yield new TextDelta('memory-write-partial', 'memory-write-message-1', 'partial', 1_710_000_000);

                throw new RuntimeException('memory-write-stream-crash-first-attempt');
            }

            $result = app(Remember::class)->handle(new Request([
                'key' => 'retry-write',
                'value' => 'retry-value',
                'scope' => 'run',
            ]));

            // Count only a write the tool accepted, so a test asserting on the
            // counter proves the retry buffered a write, not merely ran.
            if (str_starts_with($result, 'Stored ')) {
                self::$writeAttempts++;
            }

            if (self::$failAfterWrite) {
                throw new RuntimeException('memory-write-stream-failed-after-write');
            }

            $timestamp = 1_710_000_000;
            yield new TextDelta('memory-write-clean', 'memory-write-message-2', 'saved', $timestamp);
            yield new TextEnd('memory-write-end', 'memory-write-message-2', $timestamp);
            yield new StreamEnd('memory-write-stream-end', 'stop', new TextUsage(inputTokens: 1, outputTokens: 1), $timestamp);
        }, new Meta('fake', 'test'));
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function queue(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Queueing is not supported in this test fixture.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcast(AgentInput|UserMessage|Decisions|string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Broadcasting is not supported in this test fixture.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcastNow(AgentInput|UserMessage|Decisions|string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Broadcasting is not supported in this test fixture.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcastOnQueue(AgentInput|UserMessage|Decisions|string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Broadcast queueing is not supported in this test fixture.');
    }
}
