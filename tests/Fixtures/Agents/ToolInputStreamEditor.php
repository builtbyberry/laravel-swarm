<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming\UnknownStreamEvent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming\WireToolInputEvent;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

/**
 * Streams what a laravel/ai v1.4.2 provider stream carries around tool calls: the
 * lifecycle starts Swarm skips, one tool call whose input streams in two
 * fragments and then runs (`lookup`, tool-1), and one whose input is cut off and
 * never runs (`search`, tool-2). `ToolInputStreamEditor::$withUnknown` adds one
 * genuinely unrecognised event, so a test can tell the breadcrumb names only it.
 */
class ToolInputStreamEditor extends RichStreamEditor
{
    public const ARGUMENT_SECRET = 'argument-secret-649';

    public const ERROR_SECRET = 'error-secret-649';

    public static bool $withUnknown = false;

    /**
     * @param  array<int, mixed>  $attachments
     */
    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('tool-input-invocation', function (): \Generator {
            $t = 1_710_000_000;
            $arguments = ['query' => self::ARGUMENT_SECRET];

            yield new StreamStart('stream-start-1', 'fake', 'test', $t);
            yield new ReasoningStart('reasoning-start-1', 'reason-1', $t);
            yield new TextStart('text-start-1', 'message-1', $t);
            yield new TextDelta('delta-1', 'message-1', 'looking up', $t);
            yield new TextEnd('text-end-1', 'message-1', $t);
            yield WireToolInputEvent::start('tool-input-start-1', 'tool-1', 'lookup', $t);
            yield WireToolInputEvent::delta('tool-input-delta-1', 'tool-1', '{"query":', $t);
            yield WireToolInputEvent::delta('tool-input-delta-2', 'tool-1', '"'.self::ARGUMENT_SECRET.'"}', $t);
            yield new ToolCall('tool-call-1', new ToolCallData(id: 'tool-1', name: 'lookup', arguments: $arguments), $t);
            yield new ToolResult('tool-result-1', new ToolResultData(id: 'tool-1', name: 'lookup', arguments: $arguments, result: ['found' => true]), true, null, $t);

            if (self::$withUnknown) {
                yield new UnknownStreamEvent;
            }

            yield WireToolInputEvent::start('tool-input-start-2', 'tool-2', 'search', $t);
            yield WireToolInputEvent::delta('tool-input-delta-3', 'tool-2', '{"q":"'.self::ARGUMENT_SECRET, $t);
            yield WireToolInputEvent::error('tool-input-error-1', 'tool-2', 'search', '{"q":"'.self::ARGUMENT_SECRET, self::ERROR_SECRET, $t);
            yield new StreamEnd('stream-end-1', 'stop', new TextUsage(inputTokens: 1, outputTokens: 1), $t);
        }, new Meta('fake', 'test'));
    }
}
