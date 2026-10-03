<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use RuntimeException;

final class ParallelLiveStreamReleasedFailureBranch extends SerializationBoundaryAgent
{
    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $path = is_string($prompt) ? $prompt : '';

        return new StreamableAgentResponse('released-failure-invocation', function () use ($path): \Generator {
            $deadline = hrtime(true) + 5_000_000_000;
            while (! file_exists($path.'.release-failure')) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for the completed-sibling test release barrier.');
                }
                usleep(10_000);
            }

            yield from [];

            throw new RuntimeException('parallel branch failed after sibling completion');
        }, new Meta('fixture', 'parallel-live'));
    }
}
