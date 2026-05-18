<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Marker interface for exceptions that should halt swarm execution rather
 * than be swallowed by the audit/observability isolation that normally
 * protects runs from evidence-write failures.
 *
 * The SwarmRunner detects this marker on caught exceptions and surfaces the
 * halt as a deliberate, attributable run-level failure (the history store
 * records the failure; the exception is rethrown to the dispatch caller).
 *
 * Reserved for regulated workloads that require strict no-unsigned-evidence
 * or no-unattributed-evidence semantics. The default audit configuration
 * never produces a halting exception; opt in via SinkFailureHandler returning
 * SinkFailureDecision::Halt (e.g. by setting swarm.audit.failure_policy=halt).
 */
interface HaltsSwarmExecution {}
