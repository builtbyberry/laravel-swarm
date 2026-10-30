<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Closure;
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
use RuntimeException;
use Stringable;
use Throwable;

/**
 * A scripted streaming agent for durable stream-batching tests.
 *
 * It streams `$deltas` text deltas with no TextEnd unless `$textEnd` is set, so
 * no structural event writes held deltas for it — only the advancer's flush does.
 * On attempts up to `$failuresBeforeSuccess` it throws `$failWith` after
 * `$failAfter` deltas, the way a crashed node attempt would.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class BatchingStreamEditor implements Agent
{
    public static int $deltas = 4;

    public static bool $textEnd = false;

    public static int $failuresBeforeSuccess = 0;

    public static int $failAfter = 5;

    /** @var class-string<Throwable> */
    public static string $failWith = RuntimeException::class;

    public static int $attempts = 0;

    /** Called once per attempt after its deltas, before it fails or ends. */
    public static ?Closure $midStream = null;

    /**
     * @param  class-string<Throwable>  $failWith
     */
    public static function reset(int $deltas = 4, bool $textEnd = false, int $failuresBeforeSuccess = 0, int $failAfter = 5, string $failWith = RuntimeException::class): void
    {
        self::$deltas = $deltas;
        self::$textEnd = $textEnd;
        self::$failuresBeforeSuccess = $failuresBeforeSuccess;
        self::$failAfter = $failAfter;
        self::$failWith = $failWith;
        self::$attempts = 0;
        self::$midStream = null;
    }

    public function __construct(private string $label = 'batch') {}

    public function instructions(): Stringable|string
    {
        return 'You are a batching stream editor.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse(
            invocationId: 'batching-'.$this->label,
            text: $this->label.'-out',
            usage: new TextUsage,
            meta: new Meta('fake', 'test'),
        );
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $label = $this->label;
        $attempt = ++self::$attempts;
        $fails = $attempt <= self::$failuresBeforeSuccess;
        $deltas = $fails ? self::$failAfter : self::$deltas;
        $textEnd = self::$textEnd;
        $failWith = self::$failWith;
        $midStream = self::$midStream;

        return new StreamableAgentResponse('batching-'.$label.'-'.$attempt, function () use ($label, $attempt, $fails, $deltas, $textEnd, $failWith, $midStream): Generator {
            $timestamp = 1_710_000_000;
            $message = $label.'-message-'.$attempt;

            for ($i = 1; $i <= $deltas; $i++) {
                yield new TextDelta($label.'-a'.$attempt.'-d'.$i, $message, $label.'-'.$attempt.'-'.$i.' ', $timestamp);
            }

            if ($midStream !== null) {
                $midStream($attempt);
            }

            if ($fails) {
                throw new $failWith('batching stream crashed mid-node on attempt '.$attempt);
            }

            if ($textEnd) {
                yield new TextEnd($label.'-a'.$attempt.'-text-end', $message, $timestamp);
            }

            yield new StreamEnd($label.'-a'.$attempt.'-stream-end', 'stop', new TextUsage(inputTokens: 1, outputTokens: 1), $timestamp);
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
