# Upgrade assistant

`swarm:upgrade` previews dependency fixes using a selected recipe. The default
remains **0.25-to-0.26**, targeting v0.26.1 and also inspecting existing v0.26
applications. The explicit **0.26-to-0.27** recipe covers the Laravel AI 1.0
transition. Both produce an upgrade checklist; neither certifies deployment or
database readiness.

## Laravel AI 1.0 recipe

Use the reviewed v0.27 candidate source checkout/archive for candidate rehearsal.
Keep its `bin` and `src` directories together; no Composer install is needed to
run the standalone script. The published v0.26.1 script does not contain this new
recipe. Candidate targets are not proof of published dependency availability.

```bash
php /path/to/reviewed-swarm-candidate/bin/swarm-upgrade \
  --path=/path/to/disposable-application --recipe=0.26-to-0.27 --json
```

After installing the matching package, Artisan exposes the same selected report:

```bash
php artisan swarm:upgrade --recipe=0.26-to-0.27 --json
```

The supported upgrade source is stable Swarm 0.26.x. Already-target 0.27.x gets
verification advice with no repeat rewrite or downgrade. Other or unknown source
lines require manual review with unchanged files. Core targets 0.27.0 and native
AI targets a minimum 1.0.0; supported newer 1.x requirements are not lowered.
The tool never adds an absent optional package or direct requirement for a
transitive dependency. At this candidate stage, present companions have unresolved
targets and block applying the new recipe until the reviewed compatibility map is
complete. Their target is reported as unavailable, not an invented release.

Select only actions from your report and use its digest with the same recipe:

```bash
php artisan swarm:upgrade --recipe=0.26-to-0.27 \
  --apply=dependency:builtbyberry/laravel-swarm,dependency:laravel/ai \
  --expect=THE_64_CHARACTER_PREVIEW_DIGEST --yes
php artisan swarm:upgrade --recipe=0.26-to-0.27 --restore=BACKUP_ID --yes
```

An application's report may expose fewer actions or block all application. A
recipe/target change invalidates a preview just as changed manifest, lock or
installed bytes do. Restore requires the matching backup recipe and the unchanged
after-image. Old schema-1 backups still restore under the old default or explicit
old recipe. A manifest restore does not restore packages, native tables or effects.

Both entry points validate selected recipe identity and share report/help/mutation
behavior. Standalone refuses repeated options; Artisan retains Symfony's ordinary
option parsing. Invalid selectors do not acquire a valid target identity.

Follow the [native conversation upgrade procedure](native-conversation-upgrade.md)
for stopped writers, pending-turn disposition, the executable application-owned
migration, configured table/connection rehearsal, custom stores, authorization,
privacy and coordinated restore. The static report never reads native rows or
claims to know pending counts. Keep `runtime_verified=false` until your own
application verification; this field is never promoted by the assistant.

## Run before upgrading

The following source-download instructions and omitted-selector examples describe
the preserved **0.25-to-0.26** default recipe.

An old application's Artisan bootstrap may fail after changing dependencies. To
inspect it first, download and extract the **v0.26.1 source archive** from the
[release page](https://github.com/builtbyberry/laravel-swarm/releases/tag/v0.26.1)
into a separate directory. With PHP 8.4 or later, run the extracted script:

```bash
php /path/to/laravel-swarm-0.26.1/bin/swarm-upgrade --path=/path/to/application
```

The extracted script needs no Composer install. It loads its own upgrade classes
and reads the target's manifest, lock and optional `installed.json` as data. It
does not load the target's bootstrap, autoloader, plugins, scripts or executable
`installed.php`. Keep the extracted `bin` and `src` directories together.

After installing v0.26.1, the same report is available through:

```bash
php artisan swarm:upgrade
vendor/bin/swarm-upgrade --path=/path/to/application
```

Artisan boots Laravel normally; use the standalone entry point when booting the
application is inappropriate. Artisan defaults to the current application's base
path; standalone defaults to the shell's current directory. Both accept `--path`.
Use `--help` for supported options.

## Preview and select safe fixes

The default operation is read-only. It reports root constraints, locked versions,
installed versions when available, source references in JSON, exact proposed dependency
changes, blocking findings, and manual verification tasks.

The recipe covers core, Laravel AI and any explicitly declared Pulse, Filament,
MCP or memory-vector companion. It does not add optional packages or add a root
requirement for a transitive dependency. Recognized exact pins remain pins;
recognized caret constraints remain carets. A caret that already permits the
recommended version needs Composer resolution rather than a manifest edit.
Other constraints, custom repositories, aliases, replacements and unsupported
version lines require manual review.

Select action IDs and copy the preview digest into a separate apply command:

```bash
php artisan swarm:upgrade --json
php artisan swarm:upgrade \
  --apply=dependency:builtbyberry/laravel-swarm,dependency:laravel/ai \
  --expect=THE_64_CHARACTER_PREVIEW_DIGEST \
  --yes
```

Use only IDs present in your own preview. Standalone accepts the same flags.
`--yes` explicitly approves the selected changes; it does not approve every
finding or a deployment. No interactive input is required. An unknown, duplicate
or unselected action is never inferred. A changed manifest, lock or installed
metadata invalidates the preview. Run another preview after any edit or apply.

The default recipe requires a readable lock establishing stable core 0.25.x or 0.26.x.
Missing installed metadata is reported as unverified. If installed metadata is
present, relevant lock/installed versions and source metadata must agree.
Malformed or unsupported evidence blocks automatic edits. A static source
reference is reported metadata, not an independent attestation of its contents.

Only selected `composer.json` string values change. Formatting, unrelated fields,
application code, lock and installed files are preserved. Resolve dependencies
separately after reviewing the report, for example by reviewing a Composer
`update --with-all-dependencies --dry-run` before the intended update. Composer
can execute application plugins and scripts; the assistant never runs it.

## Backups and restore

Apply creates `.swarm-upgrade/` inside the application with owner-only directory
permissions and owner-only backup files. Keep this directory private and out of
version control, public web roots and published artifacts. It contains original
and changed manifest contents, which may include private repository details.
The command prints a backup ID:

```bash
php artisan swarm:upgrade --restore=BACKUP_ID --yes
```

Restore checks the backup's schema and hashes and requires the current manifest
to match the recorded after-image. It refuses to overwrite later edits. If
Composer has reformatted the manifest, reconcile it manually rather than forcing
a restore. Restoring `composer.json` does **not** roll back packages, the lock,
application data or tool effects.

Writes preserve the original file's owner, group and permissions, use an atomic
same-filesystem replacement, and fail unchanged if those guarantees cannot be
met. Symbolic links, hardlinked manifests and unsafe backup storage are refused.
A persistent file lock serializes this tool's apply/restore operations. Other
editors and Composer do not honor that lock: do not run them concurrently. The
command rechecks identity and contents before replacement but cannot lock out
uncooperative writers. Backups remain available if a replacement fails; inspect
the error and current manifest before retrying. Repeating an old apply command
fails safely because its preview no longer matches.

Backups are operator-managed local files, exempt from `swarm:prune`: they are not
runtime records and automatic pruning could remove the operator's recovery copy.
After verification, remove retained backup files according to your own retention
policy. Do not delete the directory or persistent lock during an active operation.

## Finish the application upgrade

For the new recipe, use the [Laravel AI 1.0 procedure](native-conversation-upgrade.md).
For the preserved default recipe:

Follow [Upgrading to v0.26.0](../UPGRADING.md#upgrading-to-v0260) and the official
[Laravel AI upgrade guide](https://github.com/laravel/ai/blob/v0.11.2/UPGRADE.md).
The report keeps these tasks manual:

- Review native connection and stream exceptions, event/Request constructor
  overrides, queued fake behavior and model defaults in application code.
- Reconcile effects before retrying a native approval failure; Swarm does not
  provide native approval continuation.
- Retain a reader that preserves corrected denied/failed evidence. An unmodified
  v0.25 reader is not a supported rollback target once that evidence exists.
- Rehearse worker drain/restart, preserve `APP_KEY` and maintenance schedules,
  verify custom serialized jobs/stores and smoke-test the execution modes used.

This tool does not scan application source, inspect database state, run provider
calls, control workers or execute migrations. Those checks remain required even
when every suggested manifest fix is applied.

## Automation contract

`--json` returns `schema_version: 1`, the recipe/target, `status`, findings with
stable `id`/`level`/`message`, inventory, actions and the preview digest. Successful
apply includes `applied_actions` and `backup_id`; restore includes `backup_id`.
`runtime_verified` is always false. Error reports include `status: error` and a
message. Treat the digest as a stale-state guard, not a secret or signature.

| Exit | Meaning |
| --- | --- |
| 0 | A selected file operation completed, or help was displayed. |
| 1 | Inspection completed; manual upgrade verification remains. Inspect `can_apply` and findings for available fixes. |
| 2 | Invalid input, unsafe state or an I/O failure prevented the operation. |
