<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use RuntimeException;

/**
 * A runnable, provider-free agent that returns scripted text.
 *
 * Intended for starter examples, smoke tests, and "show the shape" demos
 * that need to execute end-to-end without configuring a Laravel AI provider
 * or burning API credit. Real applications swap subclasses out for normal
 * Laravel AI agents that use the `Promptable` trait.
 *
 * Subclasses override {@see reply()} to compose the scripted response from
 * the incoming prompt. The shipped `prompt()` implementation wraps the reply
 * in a standard `AgentResponse` so the swarm runner treats it identically to
 * a real provider response. `stream()`, `queue()`, and broadcast helpers
 * raise so callers get a clear signal when they try to use a streaming or
 * queued execution mode against a scripted agent — those modes need a real
 * provider, or a `Promptable` agent with `Agent::fake()` set up in the test.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
abstract class ScriptedAgent implements Agent
{
    /**
     * The system-prompt-style description of what this agent does.
     *
     * Returned verbatim so example readers can see the "shape" of the agent.
     */
    abstract public function instructions(): string;

    /**
     * Compose a scripted reply for the given prompt.
     *
     * Subclasses typically return a one-paragraph string that demonstrates
     * what a real provider response would look like for this step. The
     * input is the previous step's output (for sequential and durable
     * sequential topologies) or the original task (for parallel agents and
     * coordinators).
     */
    abstract protected function reply(string $prompt): string;

    /**
     * Invoke the agent with a given prompt.
     *
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(
        string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        return new AgentResponse(
            invocationId: 'scripted-'.Str::random(8),
            text: $this->reply($prompt),
            usage: new Usage,
            meta: new Meta('scripted-agent', static::class),
        );
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(
        string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        throw new RuntimeException(static::class.': ScriptedAgent does not support stream(). Swap to a Promptable agent with Agent::fake() for streaming demos.');
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function queue(
        string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new RuntimeException(static::class.': ScriptedAgent does not support queue(). Use a Promptable agent and a real queue worker.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcast(
        string $prompt,
        Channel|array $channels,
        array $attachments = [],
        bool $now = false,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new RuntimeException(static::class.': ScriptedAgent does not support broadcast(). Swap to a Promptable agent for broadcast demos.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcastNow(
        string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new RuntimeException(static::class.': ScriptedAgent does not support broadcastNow(). Swap to a Promptable agent for broadcast demos.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcastOnQueue(
        string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new RuntimeException(static::class.': ScriptedAgent does not support broadcastOnQueue(). Swap to a Promptable agent for broadcast demos.');
    }
}
