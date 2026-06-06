# Metadata Allowlist Governance

Run metadata is developer-supplied. Laravel Swarm does not validate, sanitize,
or shape the values. When you allowlist a metadata key with
`SWARM_AUDIT_METADATA_ALLOWLIST` or `SWARM_OBSERVABILITY_METADATA_ALLOWLIST`,
the raw value flows verbatim into every audit evidence payload or telemetry
emission for that run. For developer-supplied keys the allowlist is the only
line of defense between your application's metadata bag and your sinks (a small
set of framework-owned keys is always emitted — see [Reserved keys](#reserved-keys-bypass-the-allowlist) below).

This document covers what should go in metadata, what should not, the named
anti-patterns to avoid, and the review pattern to apply whenever the allowlist
is extended.

## Reserved keys bypass the allowlist

A small, fixed set of framework-owned metadata keys is **always emitted** on
audit and telemetry payloads regardless of the allowlist — they are run
provenance the package guarantees. The set is published as
`EvidenceEnvelope::RESERVED_METADATA_KEYS`:

- `actor` — the resolved identity bound at run entry (`RunContext::withActor()`).
- `conversation_id` — the conversation a run belongs to
  (`RunContext::withConversationId()`), since v0.12.0.

Because their **values** flow to your sinks even when the allowlist is empty,
keep them opaque: bind an actor reference and an **opaque, non-PII**
conversation id (a UUID or namespaced surrogate — never an email address, raw
chat-thread title, or anything you would not want verbatim in a SIEM). The
allowlist is the line of defense for *developer-supplied* metadata; reserved
keys are out of its scope by design.

## Metadata vs Capture Payloads

The two paths into Laravel Swarm's persistence and audit surfaces look similar
but have different contracts.

**Capture payloads** carry the substance of a swarm run — the input prompt,
agent outputs, step I/O, artifact contents. They are governed by capture
flags (`swarm.capture.inputs`, `outputs`, `artifacts`, `active_context`) and
redacted to `[redacted]` when capture is off. Capture payloads are sealed
at rest when database encryption is enabled. They are **never** included in
audit evidence or telemetry. See [Persistence And History](persistence-and-history.md).

**Metadata** is structured tagging supplied by the developer when a swarm is
dispatched — `tenant_id`, `workflow_type`, internal correlation IDs, feature
flags. It exists to **classify** runs so operators can filter dashboards,
audit logs, and telemetry. Metadata values are excluded from sink payloads by
default; only the allowlist lets a specific key's value through.

The rule of thumb:

> If the field describes **which run this is**, it is metadata. If it
> describes **what the run is doing or saying**, it is capture.

Metadata that belongs:

- Tenant or organization identifiers used for partitioning and access control.
- Workflow type, lifecycle stage, or feature-flag bucket.
- Internal correlation IDs for cross-system tracing (job ID, request trace
  ID, deploy hash).
- Coarse routing labels (`region`, `priority`, `tier`).

Metadata that does not belong:

- Raw user prompts, agent outputs, document text, tool arguments. Use capture.
- Customer-supplied free text (chat messages, support ticket bodies). Use
  capture.
- Anything you would not want to see verbatim in a SIEM query result.

## Named Anti-Patterns

Audit and telemetry sinks are typically wired to long-retention destinations
(SIEMs, object storage, append-only tables). Allowlisted metadata values land
there in plaintext, often outside the application's encryption-at-rest
boundary. The following patterns should be rejected during review.

### Raw User Identifiers

Email addresses, phone numbers, government IDs, and unhashed customer IDs.
Allowlisting `customer_email` puts every email address into the audit stream.

**Prefer:** a tenant-scoped surrogate (`customer_uuid`, `account_id`) that
does not directly identify the person. If the audit target legitimately needs
the email, perform the lookup at query time rather than capturing it on every
emission.

### Regulated Product Or Account Names

Health conditions, regulated financial product names, case identifiers from
legal or HR systems. Anything that would make the bare metadata value
sensitive on its own under HIPAA, GLBA, or analogous regimes.

**Prefer:** an opaque token (`case_token`, `product_code`) plus a separate
authoritative lookup the audit reviewer can reach.

### Authentication Material

Session tokens, API keys, signed URLs, OAuth state values. These should never
be in metadata regardless of allowlist status; allowlisting them propagates
the leak.

**Prefer:** do not pass authentication material through metadata.

### High-Cardinality Free Text

User-supplied search queries, ticket subjects, document titles. Even if
non-sensitive in isolation, high-cardinality free text bloats sink payloads
and is hostile to dashboard filtering.

**Prefer:** capture (with the appropriate flag) or a normalized category
(`query_intent`, `ticket_category`).

### Mutable PII Buckets

`metadata['user']`, `metadata['customer']`, `metadata['attributes']` — nested
objects whose shape evolves over time. Allowlisting `user` today emits a small
struct; six months later it emits an address. Nested allowlisting is not
supported, so the entire subtree flows.

**Prefer:** flatten to top-level keys with stable contracts (`tenant_id`,
`tier`, `region`).

## Review Pattern For Allowlist Changes

Treat any change to `SWARM_AUDIT_METADATA_ALLOWLIST` or
`SWARM_OBSERVABILITY_METADATA_ALLOWLIST` as a security-relevant change. The
package emits `metadata_keys` (the full set of original key names) in every
payload regardless of the allowlist, so reviewers can see whether a proposed
addition is even necessary before approving the value.

Apply this checklist before merging an allowlist change:

1. **State the use case.** Why does the sink need the value, not just the key?
   Could a downstream join cover it instead?
2. **Inspect a sample.** Pull a recent payload that already contains the key
   in `metadata_keys`. Confirm the value's actual shape and cardinality, not
   the assumed one.
3. **Apply the anti-pattern checklist.** If the value matches any pattern
   above, reject and ask for a surrogate.
4. **Confirm sink retention.** Verify the destination's retention window and
   access controls are appropriate for the value being added.
5. **Record the decision.** Note the approver, the use case, and the expected
   value shape in your change log so future reviewers see the precedent.

When the new value is dynamic enough that none of the above gives confidence,
implement a custom `SwarmAuditSink` that hashes or transforms the value
before forwarding instead of allowlisting it raw. The
[Audit Evidence Contract](audit-evidence-contract.md) covers the custom-sink
pattern.

> Laravel Swarm plans to add a validator hook in `0.4` that lets applications
> reject or transform metadata at dispatch time. Until then, allowlist
> discipline and custom sink redaction are the governance surface.

## Related Reading

- [Audit Evidence Contract](audit-evidence-contract.md) — payload schema and
  the `metadata` vs `metadata_keys` fields.
- [Observability Correlation Contract](observability-correlation-contract.md)
  — telemetry payload contract and the parallel observability allowlist.
- [Configuration](configuration.md) — `swarm.audit.metadata_allowlist` and
  `swarm.observability.metadata_allowlist` reference.
- [Persistence And History](persistence-and-history.md) — capture flags and
  redaction model for non-metadata fields.
