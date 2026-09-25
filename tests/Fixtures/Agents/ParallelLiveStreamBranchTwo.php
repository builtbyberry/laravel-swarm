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

final class ParallelLiveStreamBranchTwo extends SerializationBoundaryAgent
{
    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $path = is_string($prompt) ? $prompt : '';

        return new StreamableAgentResponse('shared-native-invocation', function () use ($path): \Generator {
            file_put_contents($path.'.branch-two-pid', (string) getmypid());
            usleep(250_000);
            yield (new TextDelta('shared-native-event', 'shared-message', 'branch-two', 1710000001))
                ->withInvocationId('shared-native-invocation');
            yield (new StreamEnd('shared-native-end', 'stop', new TextUsage(5, 7), 1710000002))
                ->withInvocationId('shared-native-invocation');
            file_put_contents($path.'.branch-two-complete', 'yes');
        }, new Meta('fixture', 'parallel-live'));
    }
}
