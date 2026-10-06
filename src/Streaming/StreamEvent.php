<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use Laravel\Ai\Streaming\Events\StreamEvent as LaravelAiStreamEvent;

/**
 * Swarm-owned base class for streaming events.
 *
 * This class extends `Laravel\Ai\Streaming\Events\StreamEvent` so the existing
 * invocation-id tracking, `type()` method, and `toArray()` contract continue
 * to work unchanged. It exists to give every `SwarmStream*` event a swarm-owned
 * ancestor, so consumers may type-hint against this seam instead of the vendor
 * class. The inheritance keeps full runtime compatibility with the vendor
 * streaming machinery while shifting the public type boundary into Swarm's
 * namespace.
 *
 * It also owns the Swarm orchestration identities restored centrally by
 * {@see Events\SwarmStreamEvent::fromArray()}: `nodeId` tags the run-structure
 * node, while `branchId`, `attemptId`, and `branchSequence` scope live parallel
 * events without rewriting provider-native identities. Absent keys on older
 * persisted logs rehydrate to null. Additive and non-breaking.
 */
abstract class StreamEvent extends LaravelAiStreamEvent
{
    public ?string $nodeId = null;

    /** Stable logical parallel branch identity (for example `parallel:0`). */
    public ?string $branchId = null;

    /** Swarm-owned identity for one live execution attempt of a branch. */
    public ?string $attemptId = null;

    /** Zero-based semantic ordering within one branch attempt. */
    public ?int $branchSequence = null;

    /**
     * The durable per-node attempt this event belongs to (#298). Stamped only on
     * events a durable advancer streams into the causal log; null for every
     * non-durable-streamed event. It is the authoritative rollback discriminator —
     * the vendor `invocationId` is nullable and cannot key attempt-distinction —
     * so on resume the prior epoch's events for a node are voided before the new
     * epoch is written. Carried as a queryable column (not the JSON payload) so the
     * resume-time void lookup is metadata-only; the store hydrates it back onto the
     * event via {@see withAttemptEpoch()} after {@see Events\SwarmStreamEvent::fromArray()}.
     */
    public ?int $attemptEpoch = null;

    public function withNodeId(string $id): static
    {
        $this->nodeId = $id;

        return $this;
    }

    public function withAttemptEpoch(int $epoch): static
    {
        $this->attemptEpoch = $epoch;

        return $this;
    }

    public function withBranchIdentity(string $branchId, string $attemptId, int $sequence): static
    {
        $this->branchId = $branchId;
        $this->attemptId = $attemptId;
        $this->branchSequence = $sequence;

        return $this;
    }

    /**
     * Additive transport identity shared by every Swarm stream event.
     *
     * @return array<string, string|int|null>
     */
    protected function transportIdentity(): array
    {
        $identity = [
            'invocation_id' => $this->invocationId,
            'node_id' => $this->nodeId,
        ];

        return [...$identity, ...$this->branchIdentity()];
    }

    /** @return array<string, string|int> */
    protected function branchIdentity(): array
    {
        return $this->branchId !== null && $this->attemptId !== null && $this->branchSequence !== null
            ? [
                'branch_id' => $this->branchId,
                'attempt_id' => $this->attemptId,
                'branch_sequence' => $this->branchSequence,
            ]
            : [];
    }
}
