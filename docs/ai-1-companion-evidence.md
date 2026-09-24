# Laravel AI 1.0 companion candidates

C4 completed four independently governed compatibility components. Each topic PR
merged into its own release branch after its configured independent change review,
fresh verification and exact-head hosted checks. The release PRs remain draft.
These are frozen **candidate sources**, not tagged releases or Packagist proof.

## Frozen source map

All companion checks used core candidate
`48ad4ef690363ca40ba7d3bd50e63e7fbe76ba4b` (C3), official AI 1.0.0
`101c7ea33cd8569d82570f753fbf38e48b7d3d95`, and temporary Composer candidate
repositories. Production manifests contain no candidate repository overrides.
C5 must install the final core candidate and all four sources together in a fresh
application; these separate package checks do not establish that combined result.

| Package | Planned version | Frozen merged source | Component / draft release PR |
| --- | --- | --- | --- |
| Pulse | 0.1.8 | `4c2ab37a6a7a0325711b07aafa373b5f4326fefd` | [11](https://github.com/builtbyberry/laravel-swarm-pulse/pull/11) / [10](https://github.com/builtbyberry/laravel-swarm-pulse/pull/10) |
| Filament | 0.3.0 | `2a0fb8f74f5a5fe73d4ddecafffa5febce0d5324` | [45](https://github.com/builtbyberry/laravel-swarm-filament/pull/45) / [44](https://github.com/builtbyberry/laravel-swarm-filament/pull/44) |
| MCP | 0.2.0 | `79b474a5ca7af3a4b731b8d594c6452248f22277` | [17](https://github.com/builtbyberry/laravel-swarm-mcp/pull/17) / [16](https://github.com/builtbyberry/laravel-swarm-mcp/pull/16) |
| memory-vector | 0.2.0 | `d7c679f822fc60fa4e08a59fc7c1ebb38f923a31` | [11](https://github.com/builtbyberry/laravel-swarm-memory-vector/pull/11) / [10](https://github.com/builtbyberry/laravel-swarm-memory-vector/pull/10) |

The merge trees equal the reviewed topic trees: Pulse `74839dfd17737c76991a7970bdd94176e82d4567`,
Filament `58ce4529626d6fa3b4cd96d4f005fe03d7cd3787`, MCP
`32bed486045b1a5eba38235b7e3e4bce68a6839f`, and vector
`52ec5da98b6570db5f45375ff1d8a306b86a68ff`. Their Marshall components are merged
and claims released. No main branch merge, tag or publication is implied.

## Manifest contracts

Pulse adds core `^0.27` while retaining `^0.22`–`^0.26`; Filament and MCP add it
while retaining `^0.19`–`^0.26`. MCP now requires official `laravel/mcp ^1.0`;
vector now requires core `^0.27` and official `laravel/ai ^1.0`. The MCP/vector
minor releases document those upstream dependency breaks. Pulse, Filament and MCP
retain their older CI profiles; vector's new requirement intentionally excludes
the old AI/core pair. PHP remains `^8.4` in all four.

The exact production composer.json SHA-256 values are:

| Package | SHA-256 |
| --- | --- |
| Pulse | `d3497631104cb34e0ee94028acd44eeb242421f23e8835bbeb44cf291f951899` |
| Filament | `26c66d22e1af89141385f40f5af38acd16a2af86d78e5077b2aa907f11dbe73f` |
| MCP | `be39ff90d14d44aaf50ff5edafa7107e572de8548f9a3e610eb8a0f0a80cf2b3` |
| memory-vector | `00b202ac5a30f95ef495f773083921c4f559af5af58b8380f8b205858b560b71` |

## Executed compatibility evidence

| Package | Actual behavior exercised | Exact-head hosted result |
| --- | --- | --- |
| Pulse | Real events through recorders/ingestion, persisted aggregate keys, 601-run totals, registered cards and installers | [12 jobs](https://github.com/builtbyberry/laravel-swarm-pulse/actions/runs/35952392760), each 23 tests / 123 assertions |
| Filament | Actual emitted resource column, detail section, widget Stat and graph Blade through all three adapters; native/legacy/mixed/unknown/zero accounting, structural nodes, masking and read-only regressions | [12 jobs](https://github.com/builtbyberry/laravel-swarm-filament/actions/runs/35952848332), each 362 tests; 1,448 assertions on native/adoption profiles, 1,445 on older profiles |
| MCP | Native authenticated HTTP discovery and all six resource reads (two fixed, four templates), zero tools/prompts, metadata/headers/errors, authorization before reads and degraded private data | [16 runtime jobs](https://github.com/builtbyberry/laravel-swarm-mcp/actions/runs/35952942926), each 39 tests / 195 assertions, plus [branch gate](https://github.com/builtbyberry/laravel-swarm-mcp/actions/runs/35952942871) |
| memory-vector | Native OpenAI/Voyage HTTP, dimensions, public memory write/query/update/forget, redaction, authorized ranking and atomic rollback; real PostgreSQL vector index | [6 jobs](https://github.com/builtbyberry/laravel-swarm-memory-vector/actions/runs/35953286849): four SQLite jobs 47 / 138, two PostgreSQL17/pgvector jobs 47 / 139 |

Required analysis/style and dependency provenance checks passed. Native minimum
and current profiles run on PHP 8.4/8.5; actual locks and installed metadata agree
on immutable source/archive references. MCP minimum is official 1.0.0
`cfa4f38f82873eeb6848527883545f98f871e229`. Minimum/current framework fixtures use
13.16.0/13.33.0. No paid provider call established this proof.

Independent review and fault evidence is attached to each component PR:
[Pulse](https://github.com/builtbyberry/laravel-swarm-pulse/pull/11#issuecomment-5807239433),
[Filament](https://github.com/builtbyberry/laravel-swarm-filament/pull/45#issuecomment-5807273034),
[MCP](https://github.com/builtbyberry/laravel-swarm-mcp/pull/17#issuecomment-5807323541),
and [vector](https://github.com/builtbyberry/laravel-swarm-memory-vector/pull/11#issuecomment-5807346378).
The configured reviews applied two lenses per Pulse/Filament/vector and six for
MCP, with no material findings; a separate fresh verifier approved each. Eight,
ten, four and ten respective controlled faults failed discriminating guards and
were restored exactly. These include dependency-verifier behavior; they do not
claim a new runtime behavior where production code was unchanged.

### Optional provider boundary

Vector's ordinary install requires neither AWS SDK nor MCP. Missing Bedrock SDK
produces actionable installation guidance. A disposable Composer dry-run resolved
patched AWS SDK 3.397.0 (`8d2c3adc6ab2d7c6160a9034867cd4bd2b535619`) with security
blocking enabled. Native AI's older SDK compatibility floor is verified from its
actual metadata, not claimed as an installable minimum: Composer refused 3.369.1
and 3.369.0 because of [the AWS advisory](https://github.com/aws/aws-sdk-php/security/advisories/GHSA-27qh-8cxx-2cr5).
The independently reviewed amendment preserves those failures and uses current
patched resolution. This is dependency and deterministic transport evidence, not
live Bedrock/provider qualification.

## Remaining phase boundaries

C5 owns the combined fresh candidate installation and final assistant target map.
Until that implementation lands, the current new recipe still refuses present
unresolved companion targets. C6 reconciles final evidence for release-wrap.
Separate wrap/readiness and later publication remain required. Publish and observe
core first, then compatible companions; only a fresh default-Packagist five-package
installation after all five releases can establish published ecosystem delivery.
If publication is partial, retain that state explicitly; do not retag or claim
completion from this candidate evidence.
