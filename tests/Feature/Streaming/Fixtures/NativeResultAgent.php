<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures;

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RichStreamEditor;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as CallData;
use Laravel\Ai\Responses\Data\ToolResult as ResultData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use RuntimeException;

class NativeResultAgent extends RichStreamEditor
{
    public function stream(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $label = is_string($prompt) ? $prompt : throw new RuntimeException('Fixture expects a string task.');
        $invocation = str_contains($label, 'missing-id') ? null : 'invocation-'.$label;
        $stream = new StreamableAgentResponse('invocation-'.$label, function () use ($label, $invocation): \Generator {
            $call = new CallData('shared-call', 'lookup', ['secret' => 'argument-secret'], 'shared-result');
            $result = new ResultData('shared-call', 'lookup', $call->arguments, 'result-secret-'.$label, 'shared-result',
                denied: str_contains($label, 'denied'), failed: str_contains($label, 'failed'));
            $events = [
                new TextDelta($label.'-delta', 'message-'.$label, 'output-'.$label, 1710000001),
                new ReasoningDelta($label.'-reasoning', 'reason-'.$label, 'reason-secret', 1710000002, ['summary' => 'summary-secret']),
                new ReasoningEnd($label.'-reasoning-end', 'reason-'.$label, 1710000003, ['summary' => 'summary-secret']),
                new ToolCall($label.'-call', $call, 1710000004),
                new ToolResult($label.'-result', $result, $result->successful(), $result->error(), 1710000005, denied: $result->denied),
                new TextEnd($label.'-text-end', 'message-'.$label, 1710000006),
                new StreamEnd($label.'-end', 'stop', new Usage(promptTokens: 2, completionTokens: 3), 1710000007),
            ];
            foreach ($events as $event) {
                if ($invocation !== null) {
                    $event->withInvocationId($invocation);
                }
                yield $event;
            }
        }, new Meta('fixture', 'model'));
        if (str_contains($label, 'native-callback')) {
            $stream->then(fn () => throw new RuntimeException('native callback failed after output'));
        }

        return $stream;
    }
}
