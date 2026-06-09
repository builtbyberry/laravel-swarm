<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Telemetry\EvidenceEnvelope;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;

/**
 * Regression coverage for the audit evidence envelope's schema_version contract.
 *
 * The shared envelope bumped from "1" to "2" in v0.5.0 alongside the command.*
 * actor-unification work, and from "2" to "3" in v0.12.0 alongside the
 * CaptureDecision::Skip true-omission work (see UPGRADING.md + docs/audit-evidence-contract.md).
 * Any code path that emits an envelope MUST pull schema_version from
 * EvidenceEnvelope::SCHEMA_VERSION rather than hard-coding a literal — otherwise
 * downstream consumers reading the field can't trust it, and the bump-on-shape-
 * change rule is silently broken.
 *
 * This file exists so a future contributor cannot regress the contract without
 * also failing a test that names the rule explicitly.
 *
 * @see https://github.com/builtbyberry/laravel-swarm/issues/76
 */

/**
 * Every category emitted through SwarmAuditDispatcher anywhere in src/.
 *
 * Sourced via `rg "->emit\('([a-z.]+)'" src/` and filtered to audit categories
 * (telemetry-only categories like stream.event, broadcast.event are covered by
 * the parallel SwarmTelemetryDispatcher contract — see SwarmTelemetryDispatcherTest).
 *
 * If a new category is added to the dispatcher, append it here. The list is
 * intentionally explicit (not introspected) so adding a category forces the
 * author to think about the schema contract.
 */
const AUDIT_CATEGORIES = [
    // run.*
    'run.started',
    'run.completed',
    'run.failed',
    // step.*
    'step.started',
    'step.completed',
    // durable.*
    'durable.pause_requested',
    'durable.paused',
    'durable.resumed',
    'durable.cancel_requested',
    'durable.cancelled',
    'durable.completed',
    'durable.failed',
    'durable.checkpointed',
    'durable.checkpointed_hierarchical',
    // child.*
    'child.started',
    'child.completed',
    'child.failed',
    // signal / wait / progress
    'signal.received',
    'wait.created',
    'wait.started',
    'wait.timed_out',
    'progress.recorded',
    // job lifecycle
    'job.failed',
    // command.* (actor-unified in v0.5.0)
    'command.audit_reconcile',
    'command.cancel',
    'command.pause',
    'command.prune',
    'command.recover',
    'command.relay',
    'command.resume',
    // webhook.*
    'webhook.signal_received',
    'webhook.start_accepted',
    'webhook.start_conflict',
    'webhook.start_duplicate',
    'webhook.start_failed',
    'webhook.start_in_flight',
];

test('EvidenceEnvelope::SCHEMA_VERSION is "3" — bumped in v0.12.0', function (): void {
    // Hard-coded literal here is intentional: this assertion is the canary that
    // catches an inadvertent further bump without a coordinated CHANGELOG /
    // UPGRADING / docs update. v0.12.0 bumped "2" -> "3" for CaptureDecision::Skip
    // true omission (see UPGRADING.md + docs/audit-evidence-contract.md).
    expect(EvidenceEnvelope::SCHEMA_VERSION)->toBe('3');
});

test('SwarmAuditDispatcher::SCHEMA_VERSION mirrors EvidenceEnvelope::SCHEMA_VERSION', function (): void {
    expect(SwarmAuditDispatcher::SCHEMA_VERSION)->toBe(EvidenceEnvelope::SCHEMA_VERSION);
});

test('every audit category dispatched through SwarmAuditDispatcher carries schema_version "3"', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    $dispatcher = app(SwarmAuditDispatcher::class);

    foreach (AUDIT_CATEGORIES as $category) {
        // A minimal payload is fine — the dispatcher enriches every shape the
        // same way via EvidenceEnvelope::enrich.
        $dispatcher->emit($category, ['run_id' => 'r-'.$category]);
    }

    $records = $sink->allRecords();
    expect($records)->toHaveCount(count(AUDIT_CATEGORIES));

    foreach ($records as $record) {
        expect($record)
            ->toHaveKey('schema_version')
            ->and($record['schema_version'])->toBe('3')
            ->and($record['schema_version'])->toBe(EvidenceEnvelope::SCHEMA_VERSION);
    }
});

test('dispatcher emit does not let a caller-supplied schema_version override the envelope', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);

    // A buggy caller passes a stale schema_version (e.g. copy-pasted "1" from
    // a v0.4-era fixture). The dispatcher's enrich() merges via array_merge
    // with the envelope keys LAST, so the envelope must win.
    app(SwarmAuditDispatcher::class)->emit('run.started', [
        'run_id' => 'stale-caller',
        'schema_version' => '1',
    ]);

    $record = $sink->allRecords()[0];
    expect($record['schema_version'])->toBe('3');
});
