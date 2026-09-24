# Laravel AI 1.0 persisted-state upgrade evidence

This report covers representative Swarm v0.26.3 workflow data read by the
v0.27.0 candidate. Application-owned native conversation conversion has its own
[migration guide](native-conversation-upgrade.md) and actual MySQL/PostgreSQL
fixtures. The [54-row preservation ledger](ai-1-preservation-evidence.md) records
execution modes, exact tests, native boundaries and exclusions.

## Producer and reader boundary

The producer is the original Swarm v0.26.3 source
`38b3b649f3416a31c8d1f39ecefd77446e676a3c`, installed in a separate detached
checkout using a frozen Composer lock and official Laravel AI 0.11.2
`ee2c5162838d440c4e2e629ea93c8c87e838eaed`. The candidate does not generate or
rewrite the historical artifact. The existing v0.25 artifact remains unchanged.

The fixture uses synthetic data and a fixed test key in disposable databases.
Randomized ciphertext means regeneration is not byte-identical. Provenance and
frozen artifact checks distinguish the accepted producer output from later
reproductions. This is representative package payload proof, not a guarantee
for arbitrary application classes serialized into queues.

The [frozen fixture and producer instructions](../tests/Feature/Adoption/Fixtures/Upgrade/README.md)
and [candidate reader tests](../tests/Feature/Adoption/NativeUpgradeCompatibilityTest.php)
cover completed histories, pending/waiting/expired-running cursors after one old
completed step, durable parallel joins, coordinated queue joins, all five
supported job classes, and stored replay. Tests compare raw completed evidence,
sealed columns and agent call counts before and after execution.

The producer lock SHA-256 is
`6a61302fcde29102943661112af82142417a54a57da08bc1e234616afcdec559`.
The original reader run passed 10 cases / 160 assertions. Final C3 gate identity
and results are recorded in the preservation ledger when complete. Neither this
SQLite historical-state lane nor a native transport fake establishes a live
provider or MySQL/PostgreSQL concurrency result; those retain separate lanes.

## Operational meaning

Drain old workers before upgrading the application and its native conversation
schema. Keep code, dependencies, stored state and encryption keys in a coordinated
backup. Upgrade readers with writers; an older reader parsing a row does not prove
it preserves new preliminary, denied, provider-tool or usage semantics. Use a
compatible reader or restore the coordinated backup rather than deleting evidence
to make a downgrade appear successful.

Completed workflow nodes retain their checkpoint evidence; recovery of an
unfinished node can repeat external effects. A named Swarm wait is not a native
pending-tool approval continuation. The separately tracked child/signal recovery
gaps remain excluded, as recorded in the preservation ledger. No adoption test
establishes a new approval bridge or exactly-once provider effect guarantee.

Legacy usage keeps its original keys and raw values. Subsequent native reports
use their native keys. Mixed aggregate usage is unavailable; that accounting
state must not cause completed work to run again or block successful output.
Companion presentation of old/new/mixed/null usage is separately verified in C4,
then the exact five-package candidate installation in C5. Publication and a fresh
Packagist-only installation remain later shipping obligations.
