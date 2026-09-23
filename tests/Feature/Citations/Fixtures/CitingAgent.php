<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures;

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RichStreamEditor;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

class CitingAgent extends RichStreamEditor
{
    public static array $calls = [];

    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        $label = class_basename(static::class);
        $number = self::$calls[$label] = (self::$calls[$label] ?? 0) + 1;

        return new AgentResponse('invocation-'.$label.'-'.$number, 'Answer '.$label,
            new TextUsage(inputTokens: 2, outputTokens: 3),
            new Meta('fixture', 'model', collect([new UrlCitation('https://secret.example/'.$label.'/'.$number, 'Title Ω '.$label, 1, 7)])));
    }

    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $response = $this->prompt($prompt);
        $id = $response->invocationId;

        return new StreamableAgentResponse($id, function () use ($response, $id): \Generator {
            yield (new TextDelta('delta-'.$id, 'message-'.$id, $response->text, 1710000000))->withInvocationId($id);
            yield (new Citation('citation-'.$id, 'message-'.$id, $response->meta->citations->first(), 1710000001))->withInvocationId($id);
            yield (new StreamEnd('end-'.$id, 'stop', $response->usage, 1710000002))->withInvocationId($id);
        }, new Meta('fixture', 'model')); // Native terminal metadata may contain no citations.
    }
}
