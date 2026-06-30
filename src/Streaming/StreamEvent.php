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
 * It also owns `nodeId` — the structural seam (#284) that tags every
 * substantive event with the run-structure node it belongs to. This mirrors
 * the vendor's `invocationId` exactly: a nullable property restored centrally
 * by {@see Events\SwarmStreamEvent::fromArray()}. An absent or null `nodeId`
 * means a top-level event with no enclosing node; old persisted logs that
 * predate the grammar simply lack the key and rehydrate to null. Additive and
 * non-breaking.
 */
abstract class StreamEvent extends LaravelAiStreamEvent
{
    public ?string $nodeId = null;

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
}
