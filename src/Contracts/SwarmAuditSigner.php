<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Testing\Audit\RecordingSwarmAuditSigner;

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
 *
 * Optionally, an implementation may expose the id of the key that produced the
 * signature via a keyId(): ?string method:
 *
 *     public function keyId(): ?string
 *     {
 *         return 'hmac-2026-07'; // non-secret key-version label
 *     }
 *
 * When keyId() is declared and returns a non-empty string, the dispatcher
 * stamps it onto signed records as "signature_key_id" (see
 * SwarmAuditDispatcher::emit()), so a sink can select the right verification
 * key across a rotation window without guessing. It is a NON-SECRET identifier
 * — an HMAC key id, a certificate fingerprint, or a key-version tag — carried
 * verbatim on the envelope under the same exposure model as
 * "signature_algorithm"; it is NOT routed through capture or redaction, so it
 * must never contain key material.
 *
 * keyId() is OPTIONAL and read defensively (the same way this package treats
 * other optional contract methods, e.g. a store's assertReady()): a signer
 * that declares only sign() keeps working unchanged and is treated exactly as
 * if keyId() returned null — the field is simply omitted. It is documented as
 * a method rather than added to the interface signature precisely so existing
 * implementers are not broken. New implementers SHOULD declare it; returning
 * null (the default behavior of the shipped
 * {@see RecordingSwarmAuditSigner})
 * keeps the field absent.
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
