<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures;

use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\PreliminaryResultAgent;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\StreamableAgentResponse;

class PreliminaryRetryAgent extends PreliminaryResultAgent
{
    public static int $attempts = 0;

    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        return parent::stream(self::$attempts++ === 0 ? 'abort' : 'ordinary', $attachments, $provider, $model, $timeout);
    }
}
