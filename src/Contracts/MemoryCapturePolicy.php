<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\DefaultMemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;

/**
 * Declarative policy for whether a memory entry may be written as-is, must be
 * redacted, or must be dropped entirely — decided at the write boundary, by
 * scope, key, run context, or actor.
 *
 * This is the write-side sibling of two contracts:
 *
 * - {@see CapturePolicy} governs the same redact/skip decision for the audit
 *   and history evidence path (step inputs, outputs, artifacts, active
 *   context). This contract applies the same discipline to memory writes, so
 *   PII redacted at the boundary never enters memory in the first place — and
 *   therefore never reaches a frozen {@see MemorySnapshot}.
 * - {@see MemoryPropagationPolicy} governs the read side (which entries an agent
 *   sees). Capture is write-time; propagation is read-time.
 *
 * Like {@see CapturePolicy}, the policy never receives the value being written
 * — only its address and the surrounding context. A decision therefore cannot
 * couple to the payload shape and cannot leak an unredacted value through its
 * own code. The redaction itself is applied by the bound
 * {@see MemoryStore} decorator using {@see SwarmCapture::REDACTED},
 * matching the sentinel the audit path already uses.
 *
 * Bind a custom implementation in the service container (or via the
 * `swarm.memory.capture_policy` config key) to redact or drop sensitive
 * entries globally. The default binding ({@see DefaultMemoryCapturePolicy})
 * returns {@see CaptureDecision::Full} for every write, preserving pre-v0.10
 * behaviour exactly.
 */
interface MemoryCapturePolicy
{
    /**
     * Decide how a single memory entry may be persisted.
     *
     * Returns one of:
     *
     * - {@see CaptureDecision::Full}   — persist the value unchanged.
     * - {@see CaptureDecision::Redact} — persist the entry with scalar values
     *   replaced by the redaction sentinel, preserving array structure and keys
     *   so the entry remains addressable.
     * - {@see CaptureDecision::Skip}   — do not persist the entry at all; no row
     *   is written and no `MemoryWritten` event is dispatched.
     *
     * The decision is keyed on `$scope` and `$key`. `$context` and `$actor` are
     * reserved for richer rules and are passed only when a caller invokes the
     * policy with them; the bundled write-time chokepoint (the
     * {@see RedactingMemoryStore} decorator) operates one entry at a time and
     * has no live {@see RunContext} or {@see Actor} handle, so it passes null
     * for both. Policies keying on either must tolerate null.
     */
    public function memory(
        MemoryScope $scope,
        string $key,
        ?RunContext $context = null,
        ?Actor $actor = null,
    ): CaptureDecision;
}
