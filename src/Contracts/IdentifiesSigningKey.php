<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;

/**
 * Optional companion to {@see SwarmAuditSigner} that names the key a signer
 * used to produce a record's signature.
 *
 * A {@see SwarmAuditSigner} that also implements this interface lets the audit
 * dispatcher stamp the returned id onto signed evidence as the
 * package-standardized `signature_key_id` field (see
 * {@see SwarmAuditDispatcher::emit()}), so a
 * sink can select the right verification key across a rotation window without
 * guessing. The opt-in is a separate interface — rather than a method on
 * {@see SwarmAuditSigner} — so existing signers that only implement `sign()`
 * keep working unchanged and are simply treated as if no key id were available.
 *
 * `signature_key_id` is dispatcher-owned: the dispatcher reads {@see keyId()}
 * and stamps the field itself. A signer MUST NOT set `signature_key_id` in
 * `sign()` — the dispatcher unsets any signer-supplied value before stamping.
 *
 * The id is a NON-SECRET identifier carried verbatim on the envelope under the
 * same exposure model as `signature_algorithm`; it is NOT routed through
 * capture or redaction, and it is a non-authoritative routing hint, not
 * attestable provenance (the `signature` is what a verifier trusts).
 */
interface IdentifiesSigningKey
{
    /**
     * Return a NON-SECRET identifier for the key that produced the signature,
     * or null when the signer does not track a key id.
     *
     * Return a non-secret label only — an HMAC key id, a certificate
     * fingerprint, or a key-version tag — never the key material itself.
     *
     * When this returns a non-empty string, the dispatcher stamps it as
     * `signature_key_id` on signed records. Null or an empty string omits the
     * field; it is never emitted as `null`.
     */
    public function keyId(): ?string;
}
