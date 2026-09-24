# Laravel AI 1.0 ecosystem proof

This procedure combines core and all four companions in a fresh Laravel application.
It verifies candidate compatibility; it does not publish packages or migrate an
existing application. The [companion map](ai-1-companion-evidence.md) records the
independently reviewed component sources and their separate platform evidence.

## Version and source identity

The selected version map is core 0.27.0, Pulse 0.1.8, Filament 0.3.0, MCP 0.2.0
and memory-vector 0.2.0, with official Laravel AI 1.x, Laravel 13 and native MCP
1.x. Every candidate package must have an explicit immutable Git source reference.
The core reference is the committed C5 source under test; the four companion
references are the merged sources in the companion map. A floating branch, missing
companion or reused vendor directory is not a valid candidate proof.

The runner records temporary Composer package repositories, source manifests,
lock and installed metadata, exact source/archive references, and verification
results. Those repositories exist only in the disposable fixture. No production
package manifest gains a path repository, replacement or version alias. A matching
source reference is provenance metadata, not a cryptographic audit of every
installed file.

## Run the reproducible proof

Use Python 3, PHP 8.4+ with the application's required extensions, Composer and
network access for dependency downloads. Start from reviewed committed source.
Create a separate sources.json with exactly the five full package names, each
mapping to its planned `version` and immutable 40-character `reference`. Use the
core commit being tested and the four frozen companion merge commits. Do not put
this temporary input or output inside a real application's working directory.

```json
{
  "builtbyberry/laravel-swarm": {"version": "0.27.0", "reference": "REPLACE_WITH_REVIEWED_40_CHARACTER_CORE_COMMIT"},
  "builtbyberry/laravel-swarm-pulse": {"version": "0.1.8", "reference": "4c2ab37a6a7a0325711b07aafa373b5f4326fefd"},
  "builtbyberry/laravel-swarm-filament": {"version": "0.3.0", "reference": "2a0fb8f74f5a5fe73d4ddecafffa5febce0d5324"},
  "builtbyberry/laravel-swarm-mcp": {"version": "0.2.0", "reference": "79b474a5ca7af3a4b731b8d594c6452248f22277"},
  "builtbyberry/laravel-swarm-memory-vector": {"version": "0.2.0", "reference": "d7c679f822fc60fa4e08a59fc7c1ebb38f923a31"}
}
```

The placeholder must be replaced; invalid or incomplete identities fail. The
output path must be new or empty. Run from the reviewed core checkout:

```bash
python3 .github/scripts/ai1-ecosystem/proof.py candidate \
  --sources=/absolute/path/sources.json --output=/absolute/path/fresh-proof
```

The runner pins official AI 1.0.0, framework 13.33.0 and MCP 1.0.0 for this
reproducible application case. It uses a fresh Composer home/cache. This case does
not replace the package's separate minimum/current/moving-development matrices.
Retain the emitted report and logs with the exact source map; use the final
committed source rather than relabeling an earlier run after edits.

## Executed behavior and limits

The application uses actual Composer discovery and Laravel bootstrap, a disposable
SQLite database and its own ephemeral encryption key. It runs package migrations
and command registration checks, then asserts workflow output and usage, persisted
history and replay, real Pulse ingestion/aggregate reads, Filament usage labels,
authenticated native MCP discovery/resource reads, and vector memory write, query,
update and deletion. Native HTTP is deterministic and stray provider requests are
blocked. The proof does not use Testbench to impersonate application installation,
run paid providers or make a live Bedrock claim.

C4 retains the full four-reader rendering and actual PostgreSQL/pgvector proofs;
this combined smoke checks installed integration without repeating every package
suite. C2/C3 retain native conversation conversion and historical-state rehearsals;
ordinary fresh-app migrations here do not authorize converting production data.

The assistant's standalone and Artisan entry points inspect the fixture. Candidate
repositories deliberately trigger the existing custom-repository refusal; an
installed candidate is not a reason to bypass that protection. Separate deterministic
metadata fixtures prove supported old/new recipe selection, present direct and
transitive companions, absent optional packages, exact/caret constraints, selected
apply and restore, and unchanged-file refusal. The old recipe/default remains
0.25-to-0.26; the new recipe is explicitly 0.26-to-0.27. Both retain
`runtime_verified=false`.

## Later publication verification

The same expected package map can be verified later against default Packagist,
with no candidate repositories and fresh application, Composer home/cache, lock
and vendor state. That mode is a later shipping obligation; its presence does not
mean it was executed during adoption. Publish and observe core first, then the
companions. If only part of the ecosystem publishes, preserve and report that
state. Do not retag or infer public availability from candidate installation.

After separately authorized publication, supply a source map with the actual
published tag/main references and run `proof.py published` with the same two
options and a different fresh output directory. It must resolve the five planned
versions from default Packagist without temporary package repositories.

Follow the [upgrade assistant guide](upgrade-assistant.md) and
[native migration/rollback procedure](native-conversation-upgrade.md) for an
application-owned rehearsal, including stopped writers, pending native turns,
coordinated backups and readers that preserve the stored evidence.
