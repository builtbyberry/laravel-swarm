<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming\UnknownStreamEvent;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * Streams an {@see UnknownStreamEvent} (so the per-step unknown set is non-empty
 * when the finally runs) and then a provider Error (so the runner throws a
 * SwarmStreamProviderException from the try). Used to prove the breadcrumb's
 * finally emit does not mask the in-flight stream exception even when the logger
 * is hostile.
 */
class UnknownThenErrorStreamEditor extends RichStreamEditor
{
    /**
     * @param  array<int, mixed>  $attachments
     */
    public function stream(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse('unknown-then-error-invocation', function (): \Generator {
            $timestamp = 1_710_000_000;

            yield new TextDelta('delta-1', 'message-1', 'partial', $timestamp);
            yield new UnknownStreamEvent;
            yield (new Error(
                id: 'provider-error-1',
                type: 'provider_rate_limited',
                message: 'Provider stream failed.',
                recoverable: true,
                timestamp: $timestamp + 1,
                metadata: ['request_id' => 'req-1'],
            ))->withInvocationId('provider-invocation-1');
        }, new Meta('fake', 'test'));
    }
}
