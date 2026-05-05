<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Closure;
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

/**
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class SerializationBoundaryAgent implements Agent
{
    private Closure $runtimeOnlyState;

    public function __construct()
    {
        $this->runtimeOnlyState = fn (): string => 'runtime-only';
    }

    public function instructions(): Stringable|string
    {
        return 'You prove parallel execution does not serialize live agent instances.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse(
            invocationId: 'serialization-boundary-agent',
            text: 'serialization-boundary:'.$prompt.':'.($this->runtimeOnlyState)(),
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
        throw new RuntimeException('Streaming is not supported in this test fixture.');
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
