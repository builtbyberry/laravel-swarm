<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

final class ParallelLiveStreamWaitingBranch extends SerializationBoundaryAgent
{
    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $path = is_string($prompt) ? $prompt : '';

        return new StreamableAgentResponse('waiting-native-invocation', function () use ($path): \Generator {
            file_put_contents($path.'.waiting-pid', (string) getmypid());
            usleep(3_000_000);
            yield new TextDelta('waiting-event', 'waiting-message', 'late', 1710000001);
            yield new StreamEnd('waiting-end', 'stop', new TextUsage, 1710000002);
            file_put_contents($path.'.waiting-complete', 'yes');
        }, new Meta('fixture', 'parallel-live'));
    }
}
