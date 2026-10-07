<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Tools\Remember;
use Illuminate\Container\Container;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Tools\Request;

/**
 * Process-safe stream fixture that invokes Remember with a declined write.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
final class DeclinedMemoryStreamAgent extends StreamingRememberAgent
{
    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $invocationId = 'declined-memory-stream-invocation';

        return new StreamableAgentResponse($invocationId, function () use ($invocationId): \Generator {
            $timestamp = 1_710_000_000;
            $arguments = ['key' => '', 'value' => 'x'];
            $callId = 'declined-remember-call-1';
            $toolInvocationId = 'declined-remember-invocation-1';
            $result = ParentInvocation::within(
                $invocationId,
                $toolInvocationId,
                fn (): string => Container::getInstance()->make(Remember::class)->handle(
                    new Request($arguments, $callId, $toolInvocationId),
                ),
            );
            $toolCall = new ToolCallData($callId, 'remember', $arguments, 'declined-remember-result-1');
            $toolResult = new ToolResultData($callId, 'remember', $arguments, $result, 'declined-remember-result-1');

            yield (new ToolCall('declined-tool-call-event', $toolCall, $timestamp))
                ->withInvocationId($invocationId);
            yield (new ToolResult('declined-tool-result-event', $toolResult, true, null, $timestamp))
                ->withInvocationId($invocationId);
            yield (new StreamEnd('declined-stream-end', 'stop', new TextUsage(inputTokens: 1, outputTokens: 1), $timestamp))
                ->withInvocationId($invocationId);
        }, new Meta('fake', 'test'));
    }
}
