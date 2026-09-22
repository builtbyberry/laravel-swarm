# Upgrade assistant

`swarm:upgrade` previews dependency fixes for an application moving from Swarm
**v0.25 to the v0.26 line**. The first recipe targets v0.26.1 and also inspects
applications already on v0.26. It produces an upgrade checklist; it does not
certify that your application is ready to deploy.

## Run before upgrading

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

Mutation requires a readable lock establishing stable core 0.25.x or 0.26.x.
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
