<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;

class FailingAgent extends PendingAgent
{
    public function prompt(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        if ($prompt === 'closure-failure') {
            throw new ContextFailure(fn () => null);
        }
        if ($prompt === 'resource-failure') {
            throw new ContextFailure(fopen('php://temp', 'r+'));
        }
        throw new \RuntimeException('ordinary-first');
    }
}
