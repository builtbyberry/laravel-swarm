<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

class FailingAgent extends PendingAgent
{
    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        if ($prompt === 'malformed-failure') {
            throw new \RuntimeException("ordinary-\xFF");
        }
        if (in_array($prompt, ['malformed-success', 'closure-success', 'wake-failure-success'], true)) {
            $response = new AgentResponse('ordinary', "output-\xFF", new TextUsage, new Meta('fake', 'test'));
            if ($prompt !== 'malformed-success') {
                $response->withToolCallsAndResults(
                    collect([new ToolCall('ordinary', 'tool', [])]),
                    collect([new ToolResult('ordinary', 'tool', [], $prompt === 'closure-success' ? fn () => null : new WakesWithFailure)]),
                );
            }

            return $response;
        }
        if ($prompt === 'closure-failure') {
            throw new ContextFailure(fn () => null);
        }
        if ($prompt === 'resource-failure') {
            throw new ContextFailure(fopen('php://temp', 'r+'));
        }
        throw new \RuntimeException('ordinary-first');
    }
}
