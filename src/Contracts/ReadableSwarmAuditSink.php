<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Optional extension of {@see SwarmAuditSink} for sinks that can list previously
 * emitted evidence records for a given run.
 *
 * Implement this contract on a custom sink to participate in
 * `php artisan swarm:trace <run_id>`. The command resolves the bound
 * `SwarmAuditSink` from the container and checks `instanceof
 * ReadableSwarmAuditSink`; sinks that do not implement it degrade gracefully
 * (the command renders outbox + run-history rows only and surfaces a clear note
 * about the limitation).
 *
 * The contract is intentionally opt-in. The shipped {@see \BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink}
 * does not implement it. Application sinks that already write to a queryable
 * store (database, SIEM with a read API, object storage with a manifest) can
 * adopt the interface without any other behavior change.
 *
 * ## Expected return shape
 *
 * `forRun()` yields evidence records previously seen by the sink for the given
 * run. Implementations may return any traversable of associative arrays
 * (generators, arrays, lazy collections). Each record SHOULD contain at least:
 *
 * - `category`     (string, required): the evidence category, e.g. `run.started`,
 *                  `step.completed`, `durable.checkpointed`, `command.cancel`.
 * - `occurred_at`  (string, required): the event timestamp the sink originally
 *                  received, in ISO-8601. swarm:trace sorts the timeline by
 *                  this field; missing values fall back to whatever the sink
 *                  itself attached on write.
 * - `run_id`       (string, optional): the run identifier. Implementations that
 *                  already filtered by run_id may omit it; the command will
 *                  treat returned records as belonging to the requested run.
 * - `payload`      (array, optional): the full evidence envelope as seen by the
 *                  sink. swarm:trace only renders this under `--include-payloads`,
 *                  so implementations may omit it for the default summary view
 *                  to reduce read cost.
 *
 * Extra fields are preserved as-is and surfaced in `--json` output. Order of
 * returned records is not required; swarm:trace sorts by `occurred_at` before
 * rendering.
 *
 * ## Constraints
 *
 * - **Read-only.** The contract must never mutate audit state. swarm:trace is
 *   a forensic tool; it never writes through this contract.
 * - **No exceptions.** Implementations should return an empty iterable when
 *   the run is unknown rather than throwing. Throwing is permitted for
 *   genuinely degraded conditions (sink unavailable, network failure) and
 *   will surface as a command-level error.
 * - **No size guarantees.** swarm:trace iterates the return value once and
 *   sorts in memory, so implementations are free to stream via a generator
 *   if the underlying store can paginate.
 *
 * @see \BuiltByBerry\LaravelSwarm\Commands\SwarmTraceCommand
 */
interface ReadableSwarmAuditSink extends SwarmAuditSink
{
    /**
     * Yield evidence records previously emitted by this sink for the given run.
     *
     * Implementations should return records belonging to the requested
     * `$runId`. swarm:trace consumes the result once and treats it as the
     * sink-side portion of the run's audit chain.
     *
     * @return iterable<array<string, mixed>>
     */
    public function forRun(string $runId): iterable;
}
