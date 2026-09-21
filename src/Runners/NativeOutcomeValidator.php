<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Throwable;

/** @internal */
class NativeOutcomeValidator
{
    /** Preserve a permanent native failure when failure reporting or cleanup also fails. */
    public static function rethrowIfUnsupported(?Throwable $exception): void
    {
        if ($exception instanceof UnsupportedNativeApprovalException || $exception instanceof ApprovalNotResumableException) {
            throw $exception;
        }
    }

    /**
     * Inspect returned worker outcomes without invoking the concurrency driver.
     *
     * @param  array<ConcurrentAgentResult|array<mixed>>  $results
     * @return array<mixed>
     */
    public function validateConcurrentResults(array $results): array
    {
        foreach ($results as $result) {
            if ($result instanceof ConcurrentAgentResult && in_array($result->failureClass(), [UnsupportedNativeApprovalException::class, ApprovalNotResumableException::class], true)) {
                $result->value();
            }
        }

        return array_map(static fn (ConcurrentAgentResult|array $result): array => $result instanceof ConcurrentAgentResult ? $result->value() : $result, $results);
    }

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
