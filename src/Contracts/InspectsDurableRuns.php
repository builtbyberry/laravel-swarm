<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;

/**
 * Public, read-only inspection seam over a durable run's persisted state, for
 * companion packages and external readers that DISPLAY run data (a Filament
 * panel, an MCP server, a custom dashboard).
 *
 * This is the supported way to read assembled durable-run detail from outside
 * core. It is the read-only counterpart to {@see SwarmOperator} (which owns the
 * write/control verbs — pause/resume/cancel/signal/recover): a free, read-only
 * consumer binds THIS contract and never the control surface.
 *
 * ## Display-decrypt contract
 *
 * Every read here is DISPLAY-decrypted: sealed fields are opened through the
 * evidence path that honors `swarm.persistence.decrypt_failure_policy`, and —
 * unlike the operational resume reads on {@see DurableRunStore} (which decrypt
 * strictly and throw on a wrong/rotated `APP_KEY`) — a field that cannot be
 * decrypted here degrades to `null` with an explicit availability flag rather
 * than throwing. One undecryptable row never aborts the batch and never 500s a
 * display surface. See {@see DurableRunDetail} for the per-row shape.
 *
 * Consumers MUST bind this contract, never the `@internal` concrete inspector
 * or the `@internal` `SwarmPersistenceCipher`. The default binding resolves the
 * shipped database-backed inspector.
 */
interface InspectsDurableRuns
{
    /**
     * The durable run row for a run, or null when unknown. Never decrypts (the
     * run row carries no sealed input/output), so it never degrades.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array;

    /**
     * Assemble the full display-decrypted detail for a run: the run row, its run
     * history, labels, details, waits, signals, progress, child runs, parallel
     * branches, and hierarchical node outputs. Throws when the run is unknown.
     */
    public function inspect(string $runId): DurableRunDetail;

    /**
     * Inspect every run currently carrying the given labels, newest bound first.
     *
     * @param  array<string, bool|int|float|string|null>  $labels
     * @return array<int, DurableRunDetail>
     */
    public function inspectByLabels(array $labels, int $limit = 50): array;
}
