<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Generator;
use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use RuntimeException;
use Stringable;

/**
 * A streaming agent that crashes mid-stream on its first attempt(s) and streams
 * cleanly thereafter (#298 per-node streaming resume tests).
 *
 * On a failing attempt it yields one partial `TextDelta` — which the durable sink
 * appends to the causal log under the crashed attempt's epoch — and then throws,
 * so resume has a real prior attempt to retract. On the successful attempt it
 * yields a distinct clean text + `StreamEnd`. Event ids embed the attempt number
 * so each attempt's events are addressable (and distinct) in the log.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class FlakyStreamEditor implements Agent
{
    public static int $attempts = 0;

    public static int $failuresBeforeSuccess = 1;

    public static function reset(int $failuresBeforeSuccess = 1): void
    {
        self::$attempts = 0;
        self::$failuresBeforeSuccess = $failuresBeforeSuccess;
    }

    public function instructions(): Stringable|string
    {
        return 'You are a flaky stream editor for durable resume tests.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse('flaky-stream', 'unused', new Usage, new Meta('fake', 'test'));
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $attempt = ++self::$attempts;
        $fails = $attempt <= self::$failuresBeforeSuccess;

        return new StreamableAgentResponse('flaky-stream-'.$attempt, function () use ($attempt, $fails): Generator {
            $timestamp = 1_710_000_000;

            // Always emit a partial delta first so a failing attempt leaves a real
            // event in the log for resume to retract.
            yield new TextDelta('flaky-delta-'.$attempt, 'flaky-message-'.$attempt, 'partial-'.$attempt, $timestamp);

            if ($fails) {
                throw new RuntimeException('flaky stream crashed mid-node on attempt '.$attempt);
            }

            yield new TextDelta('flaky-delta-clean-'.$attempt, 'flaky-message-'.$attempt, '-done', $timestamp);
            yield new TextEnd('flaky-text-end-'.$attempt, 'flaky-message-'.$attempt, $timestamp);
            yield new StreamEnd('flaky-stream-end-'.$attempt, 'stop', new Usage(promptTokens: 1, completionTokens: 1), $timestamp);
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
