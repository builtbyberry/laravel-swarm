<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\DurableStreamingDigest;

use Laravel\Ai\Contracts\Agent;
use Generator;
use Illuminate\Broadcasting\Channel;
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
 * A provider-free streaming agent for the durable-streaming-digest example.
 *
 * The corpus's usual `ScriptedAgent` cannot stream — its `stream()` throws — so
 * a `#[DurableStreaming]` swarm needs a worker that actually yields token deltas.
 * This agent does exactly that, entirely offline: `stream()` returns a
 * `StreamableAgentResponse` wrapping a generator that hand-yields a sequence of
 * `TextDelta` tokens, then a `TextEnd`, then a `StreamEnd`. No provider, no API
 * key, no network — the tokens are scripted, the streaming grammar is real.
 *
 * Each instance carries a `$label` so its event ids are unique across steps (a
 * durable run stamps node id + attempt epoch on every event, but the event uuids
 * must still be distinct per node), and a list of `$chunks` — the scripted
 * tokens it streams, one `TextDelta` per chunk, in order.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class ScriptedStreamingEditor implements Agent
{
    /**
     * @param  list<string>  $chunks  The scripted tokens streamed, one delta each.
     */
    public function __construct(
        private string $label = 'section',
        private array $chunks = ['Scripted ', 'streamed ', 'output.'],
    ) {}

    public function instructions(): Stringable|string
    {
        return 'You stream a short section of a digest, one token at a time.';
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        return new AgentResponse(
            invocationId: 'digest-'.$this->label,
            text: implode('', $this->chunks),
            usage: new Usage,
            meta: new Meta('scripted', 'digest'),
        );
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $label = $this->label;
        $chunks = $this->chunks;

        return new StreamableAgentResponse('digest-'.$label, function () use ($label, $chunks): Generator {
            $timestamp = 1_710_000_000;

            foreach ($chunks as $index => $chunk) {
                yield new TextDelta($label.'-delta-'.$index, $label.'-message', $chunk, $timestamp);
            }

            yield new TextEnd($label.'-text-end', $label.'-message', $timestamp);
            yield new StreamEnd($label.'-stream-end', 'stop', new Usage(promptTokens: 1, completionTokens: 1), $timestamp);
        }, new Meta('scripted', 'digest'));
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function queue(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Queueing is not supported by this scripted streaming agent.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcast(string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Broadcasting is not supported by this scripted streaming agent.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcastNow(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        throw new RuntimeException('Broadcasting is not supported by this scripted streaming agent.');
    }

    /**
     * @param  SwarmBroadcastChannels  $channels
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function broadcastOnQueue(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        throw new RuntimeException('Broadcast queueing is not supported by this scripted streaming agent.');
    }
}
