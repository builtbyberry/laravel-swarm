<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use RuntimeException;
use Stringable;

class FlakyDurableAgent implements Agent
{
    public static int $attempts = 0;

    public static int $failuresBeforeSuccess = 1;

    /**
     * @var class-string<\Throwable>|null
     */
    public static ?string $exceptionClass = RuntimeException::class;

    public static function reset(int $failuresBeforeSuccess = 1, ?string $exceptionClass = RuntimeException::class): void
    {
        self::$attempts = 0;
        self::$failuresBeforeSuccess = $failuresBeforeSuccess;
        self::$exceptionClass = $exceptionClass;
    }

    public function instructions(): Stringable|string
    {
        return 'You are flaky for durable retry tests.';
    }

    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        self::$attempts++;

        if (self::$attempts <= self::$failuresBeforeSuccess) {
            $class = self::$exceptionClass ?? RuntimeException::class;

            throw new $class('flaky durable failure');
        }

        return new AgentResponse('flaky-invocation', 'flaky-success', new Usage, new Meta);
    }

    public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        throw new RuntimeException('Streaming is not supported in this test fixture.');
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
