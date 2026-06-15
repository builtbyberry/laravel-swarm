<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/**
 * Cryptographically signs (or hashes) audit evidence payloads before they
 * reach the bound SwarmAuditSink.
 *
 * Bind an implementation in the service container to enable signing. The
 * default binding is absent — when no SwarmAuditSigner is bound, the audit
 * dispatcher emits payloads as-is, matching v0.3 behavior.
 *
 * The signer receives the fully enriched envelope (schema_version, category,
 * occurred_at, actor metadata if bound) and returns a new payload array.
 * Implementations MUST NOT mutate or remove existing keys — they add
 * signature fields ("signature", "signature_algorithm", "signed_at", and
 * optionally "previous_signature_id" for chain-signing audit trails).
 *
 * When an implementation adds a non-empty "signature", it MUST also add a
 * non-empty "signature_algorithm": the package signs on emit but never
 * verifies on read (verification is the sink's responsibility), so the
 * algorithm name is what makes a stored record verifiable and rotatable. The
 * dispatcher enforces this — a signature without an algorithm name is treated
 * as a signing failure and routed through the bound SinkFailureHandler.
 *
 * Signing failures route through the bound SinkFailureHandler exactly like
 * sink failures, so callers who want strict halt-on-signing-failure semantics
 * configure swarm.audit.failure_policy=halt or bind a custom handler that
 * detects signing exceptions via $exception instanceof.
 *
 * Implementations choose their own signing scope (entire payload, canonical
 * subset, etc.) and algorithm (HMAC, ECDSA, RSA, etc.). Per-category
 * filtering ("sign run.* but not step.*") is an implementation concern —
 * the signer may return the input payload unchanged when it chooses not
 * to sign a given category.
 */
interface SwarmAuditSigner
{
    /**
     * Return a new payload array with signature fields added.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sign(string $category, array $payload): array;
}
