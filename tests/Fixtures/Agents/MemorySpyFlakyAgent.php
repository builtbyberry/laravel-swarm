<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use RuntimeException;
use Stringable;

/**
 * A test fixture that spies on what SwarmMemory returns during each invocation.
 *
 * On its first attempt it throws so the durable runner schedules a retry.
 * After the retry, callers can inspect `self::$seenValues` to verify that the
 * replay coordinator served the frozen snapshot value rather than any value
 * written to memory after the first crash.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class MemorySpyFlakyAgent implements Agent
{
    public static int $attempts = 0;

    /** @var array<int, mixed> keyed by attempt number (1 = first attempt, 2 = first retry, …) */
    public static array $seenValues = [];

    public static ?string $runId = null;

    public static function reset(?string $runId = null): void
    {
        self::$attempts = 0;
        self::$seenValues = [];
        self::$runId = $runId;
    }

    public function instructions(): Stringable|string
    {
        return 'You are a memory spy for replay-determinism tests.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        self::$attempts++;

        /** @var SwarmMemory $memory */
        $memory = app(SwarmMemory::class);

        self::$seenValues[self::$attempts] = $memory->get(
            MemoryScope::Run,
            self::$runId ?? '',
            'probe-key',
        );

        if (self::$attempts === 1) {
            throw new RuntimeException('memory-spy-crash-first-attempt');
        }

        return new AgentResponse('memory-spy', 'spy-success', new Usage, new Meta);
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        throw new RuntimeException('Streaming is not supported in this test fixture.');
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
