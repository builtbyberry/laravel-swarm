<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs\Concerns;

use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;

/** @internal */
trait FailsUnsupportedNativeOutcome
{
    protected function withoutNativeOutcomeRetry(callable $callback): void
    {
        try {
            $callback();
        } catch (UnsupportedNativeApprovalException|ApprovalNotResumableException $exception) {
            // Mark the actual queue job failed before rethrowing, so a worker with
            // tries > 1 cannot release it and repeat possible tool effects.
            $this->fail($exception);

            throw $exception;
        }
    }
}
