<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;

final class ParallelLiveStreamAggregateOversizedBranch extends SerializationBoundaryAgent
{
    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $path = is_string($prompt) ? $prompt : '';

        return new StreamableAgentResponse('aggregate-oversized-native-invocation', function () use ($path): \Generator {
            $deadline = hrtime(true) + 2_000_000_000;
            while (! file_exists($path.'.waiting-pid') && hrtime(true) < $deadline) {
                usleep(10_000);
            }

            foreach (range(1, 3) as $index) {
                yield new TextDelta(
                    'aggregate-event-'.$index,
                    'aggregate-message',
                    str_repeat((string) $index, 400),
                    1710000000 + $index,
                );
            }
        }, new Meta('fixture', 'parallel-live'));
    }
}
