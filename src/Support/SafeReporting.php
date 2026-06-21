<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Containment for the "degrade safely" paths.
 *
 * When Swarm is already handling a failure — an audit sink that threw, a
 * corrupt outbox payload, a transient queue outage — the logging and
 * error-reporting stack must never become a *second* failure surface. A
 * hostile, misconfigured, or simply unavailable logger/handler should not turn
 * a contained failure into an uncaught one.
 *
 * These helpers swallow anything thrown by the logger or by the application's
 * report() handler, falling back to error_log() so the suppressed failure is
 * not completely invisible. error_log() is itself wrapped, because even it can
 * fail (e.g. an unwritable log target) and this is the last line of defence.
 *
 * @internal
 */
trait SafeReporting
{
    /**
     * Route an exception through the application's error handler without letting
     * a throwing handler escape.
     */
    protected function safeReport(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable $reportingFailure) {
            $this->lastResortLog($exception, $reportingFailure);
        }
    }

    /**
     * Log via the given PSR-3 logger without letting a throwing logger escape.
     * The level-specific method is invoked (->error()/->warning()/…) rather than
     * ->log() so behaviour is identical to a direct call — only the throw is
     * contained.
     *
     * @param  array<string, mixed>  $context
     */
    protected function safeLog(LoggerInterface $logger, string $level, string $message, array $context = []): void
    {
        try {
            $logger->{$level}($message, $context);
        } catch (Throwable $loggingFailure) {
            $this->lastResortLog($loggingFailure, message: $message);
        }
    }

    /**
     * Last-resort sink for a suppressed failure. Never throws.
     */
    private function lastResortLog(Throwable $primary, ?Throwable $secondary = null, ?string $message = null): void
    {
        try {
            $note = $message !== null ? " while logging \"{$message}\"" : '';
            $detail = $secondary !== null ? " (reporting also failed: {$secondary->getMessage()})" : '';

            error_log("[laravel-swarm] degrade-safe path suppressed an exception{$note}: {$primary->getMessage()}{$detail}");
        } catch (Throwable) {
            // error_log() itself failed; there is nothing safe left to do.
        }
    }
}
