<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Concerns;

use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Psr\Log\LoggerInterface;

/**
 * Fail-visible breadcrumb for AI stream events that a runner's instanceof
 * chain does not recognize.
 *
 * Each streaming runner maps the provider stream with an `instanceof`
 * if/elseif chain. An event type that matches no branch would otherwise be
 * dropped without a trace, leaving the frozen snapshot — the durable replay
 * source — silently incomplete. This trait turns that silent drop into a
 * single, degrade-safe log line per streamed step.
 *
 * Contract for the hosting runner:
 *  - It must expose a {@see LoggerInterface} as `$this->logger`.
 *  - The breadcrumb logs, never throws: a harmless new provider event must not
 *    abort an otherwise-successful run. Emit it from the step's `finally` so it
 *    fires on the happy path and on mid-stream abandonment alike.
 *  - Only the event *class* is recorded — never the event body. An unrecognized
 *    event may carry un-redacted user/agent content, and this breadcrumb is
 *    emitted outside the {@see SwarmCapture}
 *    redaction pipeline, so emitting any payload here would bypass it. A class
 *    name is a type identifier, not content — the same class-only discipline
 *    {@see SafeReporting} applies on the
 *    other degrade-safe paths.
 *
 * Visibility, not completeness: the breadcrumb makes the drop observable but
 * does not add the event to the snapshot. Whether a newly surfaced event type
 * should be mapped is a separate decision; until then the log line is the only
 * record that the durable snapshot is missing content for that run.
 *
 * @internal
 */
trait RecordsUnknownStreamEvents
{
    /**
     * Emit a single warning naming the unrecognized event classes seen during a
     * streamed step. No-op when the set is empty. Class names only — the event
     * payload is never logged.
     *
     * @param  array<class-string, true>  $seen  per-step accumulator, keyed by event class
     */
    protected function breadcrumbUnknownStreamEvents(array $seen, string $runId, int $stepIndex): void
    {
        if ($seen === []) {
            return;
        }

        $eventClasses = array_keys($seen);

        $this->logger->warning(
            sprintf(
                'laravel-swarm: %d unrecognized stream event type(s) were dropped from the snapshot; the durable record is incomplete for this step.',
                count($eventClasses),
            ),
            [
                'run_id' => $runId,
                'step_index' => $stepIndex,
                'event_classes' => $eventClasses,
            ],
        );
    }
}
