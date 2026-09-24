# Native conversation upgrade to Laravel AI 1.0

Native conversation storage belongs to the application and Laravel AI. Swarm's
package migrations do not upgrade these tables. A fresh application can publish
and run the installed native migration. An application with the old tables needs
a new application migration: rerunning the original migration does not convert it.

This guide accompanies the explicitly selected
[0.26-to-0.27 upgrade recipe](upgrade-assistant.md#laravel-ai-10-recipe).
The assistant reads dependency JSON only. It cannot inspect pending turns,
certify a database, run this example, or choose a backup/disposition policy.

## Rehearse, stop writers, and convert

1. Rehearse on a disposable copy of the actual application schema and data. Review
   custom table names, database connection, custom stores, direct table readers,
   serialized jobs, and your engine's backup/restore procedure. Compare retained
   message meaning as well as counts. The package tests are fixtures, not proof
   that your application's historical/custom data is convertible.
2. Stop intake, finish or reconcile in-flight provider/tool work, drain queues,
   inventory active durable work, and stop all native conversation writers.
   Resolve or deliberately abandon pending native turns using the old application
   and its effect-reconciliation procedure. Do not clear approval state merely to
   make the migration pass. Swarm does not provide native approval continuation;
   [Swarm waits and native approvals](native-outcome-boundary.md) remain distinct.
3. Take a coordinated database backup and retain the matching application code,
   dependency lock, configuration and keys. Verify restoration on disposable data.
   Record the cutover boundary so effects after the backup can be reconciled before
   any rerun. A Composer manifest backup is not a database backup.
4. While writers remain stopped, stage the tested Swarm 0.27 / Laravel AI 1.x code
   and lock. Use that staged application's autoload tree to run the new application
   migration; the example requires the native 1.x environment. Do not start new
   workers against the old schema or run AI1-only code under the old dependencies.
5. Copy and review the [executable migration example](examples/2026_09_23_000001_upgrade_native_ai_conversation_messages.php)
   into the application's migrations with an unused migration filename. Keep the
   real `ai.conversations.connection` and
   `ai.conversations.tables.conversations` / `messages` configuration available.
   Run it through Laravel's normal migrator (`php artisan migrate`), not by calling
   `up()` directly. Swarm neither registers nor installs this example.
6. Before restarting, verify schema/indexes, conversation/message IDs and counts,
   content, attachments, usage, participants/agents, timestamps, tool-result
   arguments/values/flags, and native reads through the application store. Exercise
   authorization, your workflow modes, history/replay, and recovery paths. Refresh
   autoload/opcache, start matching workers, smoke-test, then resume intake. Preserve
   Swarm's `APP_KEY`, pruning and recovery schedules.

The example performs a complete read-only preflight before adding columns. A
nonempty native `pending` map refuses conversion; a resolved marker with an empty
map is allowed. Malformed or ambiguous evidence also refuses before schema changes
and reports message identity without echoing payloads. Operators must inspect such
rows under their own access policy. The tool does not resolve or discard them.

Backfill transactions cover one conversation's writes on the configured
connection. **The migration is not portably atomic:** MySQL DDL may commit even
if a later step fails. Laravel may wrap more of the operation on other engines;
that is not a MySQL rollback guarantee. Stop writers throughout preflight and
conversion. After any partial failure, keep them stopped, inspect the actual
schema/data/migration record, and restore the rehearsed coordinated backup. Do not
automatically retry or mix old and new writers. The example refuses an already
converted or partially converted schema rather than treating it as a retry.

## Retained meaning and conversion limits

The example is based on the tagged
[Laravel AI 1.0 upgrade guide](https://github.com/laravel/ai/blob/v1.0.0/UPGRADE.md#conversation-messages-now-store-steps).
It adds `steps` and `status`, backfills them, removes `tool_calls`, `tool_results`
and `approval_state`, and adds `agent` to the native participant index. Swarm's
own tables and migrations are unchanged.

Two deliberate refinements preserve old stored meaning:

- Results on the same message are paired locally first, matching the old reader.
  This avoids the guide's conversation-wide ID map overwriting distinct completed
  turns that reuse an ID. Later-row results are associated only when the earlier
  unmatched call is unambiguous. Unknown ownership requires manual review; the
  example never guesses the nearest call or fabricates occurrence provenance.
- Migrated answered calls retain the result's executed name, arguments, result
  ID, value and independent denied/failed flags. A present null result remains a
  completed result. The native format has one arguments slot, so edited execution
  arguments take precedence over the original proposal. The original proposal
  remains in the coordinated backup, not a new native provenance field. Retaining
  proposal arguments instead would misdescribe what executed.

Message and conversation IDs/counts, content, attachments, raw usage, participant
and agent fields, and timestamps remain intact. Unrelated metadata remains; old
reasoning moves into the synthetic steps. Content and answered calls may become
two synthetic steps rather than reconstructed original generation boundaries.

As in the upstream conversion, unanswered calls are omitted, opaque provider-step
and content-block metadata is removed, and new replay/provider-tool arrays start
empty. Historical rows become completed after the pending precondition; missing
historical failures cannot be inferred. Old within-row duplicate results retain
the old reader's last-result view, not an invented finer history. Pending turns,
opaque provider replay, and original round-trip structure are not recoverable
from this conversion. Inspect those losses before running it.

The example's `down()` refuses: it cannot reconstruct dropped evidence. Before
conversion, rollback means draining and restoring the tested old code/dependency
pair. After conversion or new-format writes, use a separately verified
compatibility migration or coordinated database/code/dependency backup restore.
Simply downgrading Composer is unsafe. Reconcile post-backup external effects
before retrying any workflow.

## Custom stores and authorized access

Update application stores against the released
[ConversationStore contract](https://github.com/laravel/ai/blob/v1.0.0/src/Contracts/ConversationStore.php):

| Method | Application migration |
| --- | --- |
| `latestConversationId` | Include the agent identity in the lookup, not only the participant. |
| `storeConversation` | Honor an optional caller-supplied conversation ID. |
| `storeUserMessage` | Accept agent class and `UserMessage`; preserve attachments as well as content. |
| `storeAssistantMessage` | Accept the trailing nullable exception and preserve the nullable return. Persist native steps/status/error meaning without duplicating an empty resumed result. |
| `storeApprovalResults` | Use the conversation ID and result array; scope writes to that conversation before considering tool IDs. |

The [executable custom-store contract fixture](../tests/Feature/Adoption/Fixtures/NativeConversationUpgrade/CustomStoreContract.php)
shows forwarding signatures and discriminating native storage checks. It calls
store methods directly; it does not add a Swarm approval bridge or invoke a live
provider. Application-specific implementations still need their own tests.

Use the optional native
[PaginatesConversations](https://github.com/laravel/ai/blob/v1.0.0/src/Contracts/PaginatesConversations.php),
[ResolvesPendingApprovals](https://github.com/laravel/ai/blob/v1.0.0/src/Contracts/ResolvesPendingApprovals.php),
and [VerifiesConversationOwnership](https://github.com/laravel/ai/blob/v1.0.0/src/Contracts/VerifiesConversationOwnership.php)
interfaces where appropriate. They are not extra requirements on Swarm stores.
Native inspection is not authorization. For a frontend-supplied conversation ID,
derive participant identity from authenticated server-side state and authorize
before any transcript inspection or agent invocation.

The [application-owned authorization example](examples/with-authorized-native-conversation.php)
refuses unsupported ownership checks and denied access before calling the supplied
operation. The operation can use a supported native inspection interface or your
authorized application invocation path. Supplying identity fields from request
input would defeat this boundary. Additional tenant/role policy remains the
application's responsibility; this example is not Swarm native approval support.

## Storage and evidence boundary

Native transcript content, steps, reasoning, provider-tool data, error strings,
attachments and generated titles need the application's privacy, encryption,
access and retention policy. Swarm capture-off is not global zero retention.
Swarm database sealing and `swarm:prune` do not protect or prune native tables.

The [native upgrade fixture](../tests/Feature/Adoption/NativeConversationUpgradeTest.php)
executes this exact example and the installed native fresh migration through
Laravel's migrator. It compares retained values and uses a physically isolated
default SQLite database to detect accidental default routing. The native
connection uses SQLite in the ordinary suite; the explicitly selected
`test:native-conversation-upgrade:real-db` command requires the configured MySQL or
PostgreSQL backend in the [database workflow](../.github/workflows/tests-real-db.yml).
Fixture backup restoration proves those disposable rows/schema can be restored;
it does not certify production backup tooling, concurrent writers, external tool
effects, or published-package installation.
