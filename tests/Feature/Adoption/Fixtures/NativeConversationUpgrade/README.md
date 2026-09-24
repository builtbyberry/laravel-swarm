# Native conversation upgrade fixtures

`2026_01_11_000001_create_agent_conversations_table.php` is an unchanged copy of
the official Laravel AI v0.11.2 migration, source commit
`ee2c5162838d440c4e2e629ea93c8c87e838eaed`. This is the native schema fixture;
it is separate from the immutable Swarm workflow producer fixtures.

`NativeConversationFixture.php` supplies literal old rows and expected values for
the application migration rehearsal. The rows are synthetic and the comparison
expectations are independent of the migration transform. The default database is
an isolated SQLite connection; the native connection uses the test backend.

`CustomStoreContract.php` exercises an application decorator over the actual native
database store. Direct storage calls cover the changed interface without invoking
a provider or adding Swarm approval continuation. The main
[upgrade test](../../NativeConversationUpgradeTest.php) owns migration execution,
driver checks, authorization, semantic comparisons and restore assertions.
