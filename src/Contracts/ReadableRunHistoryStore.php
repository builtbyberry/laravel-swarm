<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Public, read-only display seam over persisted run history for companion
 * packages and external readers (a Filament panel, an MCP server, a dashboard).
 *
 * {@see RunHistoryStore::find()} is a SHARED read: it also feeds operational
 * consumers (guardrail parent lookups, hierarchical execution, durable
 * recording) that must see the raw policy-aware decrypt and, under the `throw`
 * policy, fail loud. A display surface needs the opposite — it must NEVER throw
 * and NEVER render `sw0:` ciphertext. This contract is that display twin.
 *
 * {@see findForDisplay()} returns the same shape as `RunHistoryStore::find()`
 * but every sealed member (the run context input, the run output, and each
 * step's input/output) is opened through the evidence path and **degrades per
 * field**: an undecryptable value becomes `null` alongside an explicit
 * `*_available: false` flag rather than throwing or leaking ciphertext. The
 * run/step-list projection {@see query()} decrypts nothing (it renders only
 * columns + a step count) and is display-safe as-is.
 *
 * Consumers bind THIS contract, never the `@internal` `SwarmPersistenceCipher`.
 * The default binding resolves the same instance as {@see RunHistoryStore}.
 */
interface ReadableRunHistoryStore
{
    /**
     * The full, per-field-degraded display record for a run, or null when
     * unknown. Sealed members carry a companion `*_available` flag.
     *
     * @return array<string, mixed>|null
     */
    public function findForDisplay(string $runId): ?array;

    /**
     * The lean run-list projection (columns + step count, no decryption) for a
     * runs-index surface. Identical to {@see RunHistoryStore::query()} and
     * display-safe because it never opens a sealed column.
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(?string $swarmClass = null, ?string $status = null, int $limit = 25): array;
}
