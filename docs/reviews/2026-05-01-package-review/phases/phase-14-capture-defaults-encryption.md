# Phase 14: Capture Defaults and Encryption at Rest

## Finding

Medium regulatory finding: input/output capture defaults were permissive and the
package did not apply Laravel’s own encryption primitive to persisted prompt and
agent-output columns when using the database persistence driver.

## Target Outcome (Laravel-native, not a phased “docs first” deferral)

Ship the **same shape developers expect from Laravel**: conservative defaults
for sensitive persistence, and **application-level sealing** of designated
string columns using `Illuminate\Contracts\Encryption\Encrypter` (`APP_KEY`),
analogous to encrypted casts—without claiming transparent database (TDE)
encryption that belongs to infrastructure.

## Decision

1. **Capture defaults** — Opt **out** of storing full prompts, outputs, artifacts,
   and active context by default (`config/swarm.php`). Applications that want
   rich history enable the relevant `SWARM_CAPTURE_*` flags explicitly.

2. **Encryption at rest (package scope)** — When `swarm.persistence.driver` is
   `database`, default `swarm.persistence.encrypt_at_rest` to **true** so
   sensitive string columns are sealed with the app encrypter. Operators may
   set `SWARM_ENCRYPT_AT_REST=false` only when they intentionally rely on
   database- or volume-level encryption alone.

3. **Backward compatibility** — Stored values use a deterministic `sw0:` prefix
   before the Laravel ciphertext. Reads **open** prefixed payloads and pass
   through legacy plaintext rows unchanged.

4. **Query semantics** — When encryption is enabled, SQL JSON-path prefiltering
   for `findMatching` context subsets is skipped (PHP-side
   `PersistedRunContextMatcher` still applies) because randomized ciphertext
   cannot support equality predicates in SQL.

## Implementation Notes

- `SwarmPersistenceCipher` centralizes seal/open and small helpers for context
  `input` and step I/O arrays.
- Database stores affected include `DatabaseContextStore`,
  `DatabaseRunHistoryStore`, and `DatabaseDurableRunStore` (context `input`,
  history `output` and step I/O, durable branch I/O, hierarchical node outputs,
  child run `output` and `context_payload.input`).
- README / persistence docs should state clearly: this is **Laravel encrypter**
  sealing, not a substitute for regulated archive or database TDE policies.

## Tests

- Unit tests for cipher behavior (enabled/disabled, round-trip, plaintext
  passthrough).
- Feature test that raw `swarm_contexts.input` carries the prefix while the
  store API returns the original string when encryption is on.

## Docs / Release Notes

- CHANGELOG: breaking capture defaults; new persistence encryption behavior.
- README production checklist and `docs/persistence-and-history.md`: PII,
  capture flags, `APP_KEY` rotation implications, and `SWARM_ENCRYPT_AT_REST`.

## Acceptance Criteria

- Operators see conservative capture defaults in shipped config.
- Database persistence defaults to encrypter-backed sealing for designated
  columns unless explicitly disabled.
- No false claim of package-managed database TDE; Laravel encrypter role is
  explicit.

## Follow-up (non-blocker)

- Optional extension to additional JSON payloads (artifacts, stream replay) if
  a Laravel-consistent design is validated for query and redaction behavior.
