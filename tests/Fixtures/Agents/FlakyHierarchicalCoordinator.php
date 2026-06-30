<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use RuntimeException;
use Stringable;

/**
 * A hierarchical coordinator that crashes on its first prompt() attempt and plans
 * cleanly thereafter (#311 coordinator-crash-and-resume tests).
 *
 * The durable coordinator step ships STRUCTURAL-ONLY: it opens the reserved
 * `__coordinator__` node bracket, then runs the coordinator via prompt(). Throwing
 * on the first attempt leaves that node_opened orphaned above the checkpoint — so a
 * resume must void it via voidPriorAttempt('__coordinator__', …) before the fresh
 * attempt re-plans. On the success attempt prompt() returns the route plan as a
 * JSON string, exactly as a real structured-output coordinator would.
 *
 * Attempt count is a static so a relayed/recovered job sees the same flake budget
 * across re-dispatch, mirroring {@see FlakyStreamEditor}.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class FlakyHierarchicalCoordinator implements Agent, HasStructuredOutput
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
        return 'You are a flaky routing coordinator for durable resume tests.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'start_at' => $schema->string()->required(),
            'nodes' => $schema->object()->required(),
        ];
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        $attempt = ++self::$attempts;

        if ($attempt <= self::$failuresBeforeSuccess) {
            throw new RuntimeException('flaky coordinator crashed before checkpoint on attempt '.$attempt);
        }

        return new AgentResponse(
            invocationId: 'flaky-coordinator-'.$attempt,
            text: (string) json_encode($this->routePlan()),
            usage: new Usage,
            meta: new Meta('fake', 'test'),
        );
    }

    /**
     * The single-worker route plan the coordinator resolves to on success.
     *
     * @return array<string, mixed>
     */
    private function routePlan(): array
    {
        return [
            'start_at' => 'writer_node',
            'nodes' => [
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => PlainStreamEditor::class,
                    'prompt' => 'writer-task',
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'writer_node',
                ],
            ],
        ];
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        throw new RuntimeException('Streaming is not supported in this test fixture; the coordinator ships structural-only.');
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
