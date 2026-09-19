<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;

/** @internal */
class NativeOutcomeValidator
{
    public function validateResponse(AgentResponse $response): void
    {
        if ($response->hasPendingApprovals()) {
            throw new UnsupportedNativeApprovalException;
        }
    }

    public function validateEvent(mixed $event): void
    {
        if ($event instanceof ToolApprovalRequest) {
            throw new UnsupportedNativeApprovalException;
        }
    }
}
