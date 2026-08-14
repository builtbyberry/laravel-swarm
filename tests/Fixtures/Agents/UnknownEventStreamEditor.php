<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming\UnknownStreamEvent;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * Streams one handled event (TextDelta) followed by an {@see UnknownStreamEvent}
 * the runner chains do not map, then a normal StreamEnd. The run still succeeds —
 * the unknown event must not abort it — but it should leave exactly one
 * fail-visible breadcrumb naming the unknown class.
 */
class UnknownEventStreamEditor extends RichStreamEditor
{
    /**
     * @param  array<int, mixed>  $attachments
     */
    public function stream(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('unknown-event-invocation', function (): \Generator {
            $timestamp = 1_710_000_000;

            yield new TextDelta('delta-1', 'message-1', 'editor-out', $timestamp);
            yield new UnknownStreamEvent;
            yield new StreamEnd('stream-end-1', 'stop', new Usage(promptTokens: 1, completionTokens: 1), $timestamp);
        }, new Meta('fake', 'test'));
    }
}
