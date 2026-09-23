<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\ProviderTools\Fixtures;

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\PlainStreamEditor;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

class ProviderAgent extends PlainStreamEditor
{
    public static int $calls = 0;

    public static int $failures = 0;

    public function stream(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $number = ++self::$calls;
        $invocation = 'invocation-'.$number;

        return new StreamableAgentResponse($invocation, function () use ($number, $invocation): \Generator {
            yield (new TextDelta('text-'.$number, 'message', 'answer', 1710000000))->withInvocationId($invocation);
            foreach (['searching', 'denied'] as $status) {
                // Reuse native event/item IDs deliberately across invocations.
                yield (new ProviderToolEvent('native-'.$status, 'item-reused', 'future_search_call',
                    ['secret-key' => ['query' => 'canary-secret Ω', 'flag' => false, 'ratio' => 1.5, 'count' => 0, 'empty' => [], 'null' => null]],
                    $status, 1710000001, 'fixture'))->withInvocationId($invocation);
                if (self::$failures > 0) {
                    self::$failures--;
                    throw new \RuntimeException('fixture interruption');
                }
            }
            yield (new StreamEnd('end-'.$number, 'stop', new Usage, 1710000002))->withInvocationId($invocation);
        }, new Meta('fixture', 'model'));
    }
}
