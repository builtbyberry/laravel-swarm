<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures;

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RichStreamEditor;
use Generator;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall as CallData;
use Laravel\Ai\Responses\Data\ToolResult as ResultData;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use RuntimeException;

class PreliminaryResultAgent extends RichStreamEditor
{
    public static int $calls = 0;

    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $scenario = is_string($prompt) ? $prompt : throw new RuntimeException('Fixture requires a string.');
        self::$calls++;

        return new StreamableAgentResponse('parent-'.$scenario, function () use ($scenario): Generator {
            foreach (self::events($scenario) as $event) {
                yield $event->withInvocationId('parent-'.$scenario);
            }
        }, new Meta('fixture', 'model'));
    }

    public static function events(string $scenario): Generator
    {
        $arguments = ['secret' => ['input' => 'argument-secret']];
        if ($scenario === 'unencodable') {
            $arguments['bad'] = "\xB1";
        }
        $sequence = 0;
        foreach (['a', 'b'] as $id) {
            yield new ToolCall('call-'.$id, new CallData($id, 'lookup', $arguments, 'result-'.$id), 1710000000 + $sequence++);
        }
        $nestedDenied = $scenario === 'denied';
        $eventDenied = $scenario === 'event-denied';
        $failed = $scenario === 'failed';
        for ($i = 1; $i <= 4; $i++) {
            foreach (['a', 'b'] as $id) {
                $value = $scenario === 'unencodable' ? "\xB1" : str_repeat('partial-secret-'.$id, $i * 1024);
                yield new ToolResult('partial-'.$id.'-'.$i,
                    new ResultData($id, 'lookup', $arguments, $value, 'result-'.$id, denied: $nestedDenied, failed: $failed),
                    ! $nestedDenied && ! $failed, $nestedDenied || $failed ? 'error-secret' : null,
                    1710000000 + $sequence++, denied: $eventDenied, preliminary: true);
            }
        }
        if ($scenario === 'abort') {
            throw new RuntimeException('ordinary stream abort after partials');
        }
        foreach (['b', 'a', 'a'] as $index => $id) {
            yield new ToolResult('final-'.$index,
                new ResultData($id, 'lookup', $arguments, 'complete-secret-'.$id, 'result-'.$id, denied: $nestedDenied, failed: $failed),
                ! $nestedDenied && ! $failed, $nestedDenied || $failed ? 'error-secret' : null,
                1710000000 + $sequence++, denied: $eventDenied);
        }
        yield new TextDelta('output', 'message', 'final output', 1710000000 + $sequence++);
        yield new StreamEnd('end', 'stop', new TextUsage(2, 3), 1710000000 + $sequence);
    }
}
