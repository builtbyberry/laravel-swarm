<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use Closure;
use Illuminate\Concurrency\ForkDriver;
use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Contracts\Concurrency\Driver;
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
     * Inspect all built-in concurrent worker outcomes before selecting a batch failure.
     * Other drivers retain their own execution and exception semantics.
     *
     * @param  array<Closure>  $callbacks
     * @return array<mixed>
     */
    public function runConcurrent(Driver $driver, array $callbacks): array
    {
        if (! $driver instanceof ProcessDriver && ! $driver instanceof ForkDriver) {
            return $driver->run($callbacks);
        }

        $wrapped = [];
        foreach ($callbacks as $key => $callback) {
            $wrapped[$key] = static function () use ($callback): ConcurrentAgentResult {
                return ConcurrentAgentResult::capture($callback);
            };
        }
        $results = $driver->run($wrapped);

        foreach ($results as $result) {
            if (in_array($result->failureClass(), [UnsupportedNativeApprovalException::class, ApprovalNotResumableException::class], true)) {
                $result->value();
            }
        }

        return array_map(static fn (ConcurrentAgentResult $result): array => $result->value(), $results);
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
