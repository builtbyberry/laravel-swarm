<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Citation;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\StructuredStep;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StructuredAgentResponse;

final class RichSerializationBoundaryAgent extends SerializationBoundaryAgent
{
    /**
     * @param  array<int, mixed>  $attachments
     * @param  Lab|array<int|string, mixed>|string|null  $provider
     */
    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        $call = new ToolCall('process-call', 'lookup', ['private' => 'argument'], 'process-lookup');
        $result = new ToolResult('process-lookup', 'lookup', ['private' => 'argument'], ['private' => 'result'], 'process-result');
        $response = new StructuredAgentResponse(
            invocationId: 'serialization-boundary-agent',
            structured: ['channel' => 'process', 'typed' => true],
            text: 'process-rich-response',
            usage: new TextUsage(4, 3, null, 1, 2),
            meta: new Meta('fake', 'test', $this->citations()),
        );
        $response->withReasoning('process-reasoning');
        $response->withinConversation('process-conversation');
        $response->withStoredMessages('process-user-message', 'process-assistant-message');
        $response->withToolCallsAndResults(collect([$call]), collect([$result]));
        $response->withSteps($this->steps($call, $result));

        return $response;
    }

    /** @return Collection<int, Step> */
    private function steps(ToolCall $call, ToolResult $result): Collection
    {
        return (new Collection)->push(new StructuredStep(
            text: 'process-generation',
            structured: ['generation' => 'typed'],
            toolCalls: [$call],
            toolResults: [$result],
            finishReason: FinishReason::Stop,
            usage: new TextUsage(2, 1),
            meta: new Meta('process-step-provider', 'process-step-model'),
            reasoning: 'process-step-reasoning',
            replayBlocks: [],
        ));
    }

    /** @return Collection<int, Citation> */
    private function citations(): Collection
    {
        return (new Collection)->push($this->citation());
    }

    private function citation(): Citation
    {
        return new UrlCitation('https://example.test/process', 'process');
    }
}
