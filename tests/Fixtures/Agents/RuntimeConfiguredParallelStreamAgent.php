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

final class RuntimeConfiguredParallelStreamAgent extends SerializationBoundaryAgent
{
    public function __construct(private string $runtimeConfiguration = 'container-default')
    {
        parent::__construct();
    }

    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $path = is_string($prompt) ? $prompt : '';

        return new StreamableAgentResponse('runtime-configured-invocation', function () use ($path): \Generator {
            file_put_contents($path.'.provider-invoked', $this->runtimeConfiguration);
            yield new TextDelta('runtime-configured-event', 'runtime-configured-message', $this->runtimeConfiguration, 1710000001);
            yield new StreamEnd('runtime-configured-end', 'stop', new TextUsage, 1710000002);
        }, new Meta('fixture', 'parallel-live'));
    }
}
