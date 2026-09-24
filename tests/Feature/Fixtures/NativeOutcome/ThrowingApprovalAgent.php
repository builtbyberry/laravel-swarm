<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;

class ThrowingApprovalAgent extends PendingAgent
{
    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        throw ApprovalNotResumableException::make();
    }
}
