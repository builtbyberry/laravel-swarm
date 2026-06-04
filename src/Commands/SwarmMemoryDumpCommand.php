<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesOperatorIdentity;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Contracts\ConversationRunResolver;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryDumped;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Memory\NullSnapshotsMemory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Operator-facing exporter for a run's (or conversation's) complete memory +
 * snapshot trail.
 *
 * Where `swarm:memory:inspect` is the interactive, human-readable view of a
 * single run's frozen snapshots, this command produces a stable, machine-
 * readable export envelope built for audit packets, legal/DSAR handoff, and
 * third-party debugging — the cases where handing an outsider raw DB access is
 * not an option. It is strictly read-only and never mutates memory state.
 *
 * Subject resolution: a run id and a conversation id are both bare UUIDs and
 * indistinguishable by format, so the command resolves the subject by probe —
 * `swarm_run_histories` first (the FK-backed canonical table), then
 * Conversation-scoped `swarm_memories`. An id that matches both is refused
 * rather than guessed (an audit tool must be deterministic); pass
 * `--as=run|conversation` to force the interpretation or to script it.
 *
 * Conversation expansion: Swarm records no link between a run and a
 * conversation in v0.10 (the runtime exposes no conversation handle), so a
 * conversation export carries its Conversation-scoped entries plus whatever
 * runs a bound {@see ConversationRunResolver} resolves — the bundled default
 * resolves to none and the envelope reports `runs_expanded: false` so the
 * export is honestly self-describing.
 *
 * Reads route through the {@see MemoryStore} and {@see SnapshotsMemory}
 * contracts so the host application's chosen driver is honored. In `cache`
 * persistence mode the snapshot binding is {@see NullSnapshotsMemory} (no
 * frozen rows), so the command surfaces a configuration error rather than
 * producing a misleadingly partial export.
 */
#[AsCommand(name: 'swarm:memory:dump')]
class SwarmMemoryDumpCommand extends Command
{
    use ResolvesOperatorIdentity;
    use ResolvesStringConsoleInput;

    /**
     * Stable contract version for the export envelope. Bump when the envelope
     * or record shape changes in a way consumers must adapt to.
     */
    protected const SCHEMA_VERSION = '1.0';

    protected $signature = 'swarm:memory:dump
                            {id : The run id or conversation id to export.}
                            {--as= : Force the id interpretation. One of: run, conversation. Default: auto-detect by probe.}
                            {--format=json : Output format. One of: json, ndjson.}
                            {--include-snapshots : Embed full snapshot payloads (entries + tool calls). Default: references only.}
                            {--output= : Write the export to this file instead of stdout.}
                            {--reason= : Optional operator-supplied reason recorded in the audit trail.}';

    protected $description = 'Export the complete memory + snapshot trail for a run or conversation as a stable JSON/NDJSON envelope.';

    protected $help = <<<'HELP'
        Produces a stable, machine-readable export of every memory entry and
        snapshot recorded for a run — or, given a conversation id, the
        Conversation-scoped entries plus any runs an application-bound
        ConversationRunResolver expands the conversation into.

        Run ids and conversation ids are both bare UUIDs, so the subject is
        resolved by probe: swarm_run_histories first, then Conversation-scoped
        swarm_memories. An id that matches both is refused — pass
        --as=run|conversation to disambiguate or to script the interpretation.

        --include-snapshots embeds each snapshot's full entries + tool-call
        payload; omit it for a lighter export carrying snapshot references only
        (step index, timestamps, counts).

        --format=ndjson streams one JSON object per line (a header record, then
        one record per entry, then one per snapshot) for large exports and jq
        pipelines. --output=FILE writes to a file instead of stdout.

        Requires swarm.persistence.driver=database. Read-only — never mutates
        memory state.

        Examples:
          php artisan swarm:memory:dump 9b2c... --format=json
          php artisan swarm:memory:dump 9b2c... --include-snapshots --format=ndjson
          php artisan swarm:memory:dump 1f0a... --as=conversation --output=/tmp/audit.json
        HELP;

    public function handle(
        ConfigRepository $config,
        Connection $connection,
        MemoryStore $memory,
        SnapshotsMemory $snapshots,
        ConversationRunResolver $resolver,
        Dispatcher $events,
        SwarmAuditDispatcher $audit,
    ): int {
        $id = trim($this->argumentString('id'));

        if ($id === '') {
            return $this->failWith('id argument is required.');
        }

        $format = $this->resolveFormat();

        if ($format === null) {
            return $this->failWith('--format must be one of: json, ndjson.');
        }

        $as = $this->resolveAs();

        if ($as === false) {
            return $this->failWith('--as must be one of: run, conversation.');
        }

        // Diagnostic fallback for the cache-driver path: the resolved snapshot
        // binding is the no-op NullSnapshotsMemory, which silently returns
        // empty lookups. A dump under that driver would omit every snapshot
        // and read as a complete-but-empty export — misleading for audit. Fail
        // with the configuration hint instead.
        if ($snapshots instanceof NullSnapshotsMemory) {
            return $this->failWith(sprintf(
                'Could not read %s. Ensure swarm.persistence.driver=database and the memory-snapshots migration has run.',
                (string) $config->get('swarm.tables.memory_snapshots', 'swarm_memory_snapshots'),
            ));
        }

        $historyTable = (string) $config->get('swarm.tables.history', 'swarm_run_histories');

        $subjectType = $this->resolveSubjectType($id, $as, $connection, $historyTable, $memory);

        if ($subjectType === 'ambiguous') {
            return $this->failWith(sprintf(
                'Ambiguous id [%s]: it matches both a run and a conversation. Re-run with --as=run or --as=conversation.',
                $id,
            ));
        }

        if ($subjectType === null) {
            return $this->failWith(sprintf(
                'No run or conversation memory found for id [%s].',
                $id,
            ));
        }

        $includeSnapshots = (bool) $this->option('include-snapshots');

        $export = $subjectType === 'run'
            ? $this->buildRunExport($id, $memory, $snapshots, $resolver)
            : $this->buildConversationExport($id, $connection, $historyTable, $memory, $snapshots, $resolver);

        $envelope = $this->envelope($subjectType, $id, $includeSnapshots, $export);

        $rendered = $format === 'ndjson'
            ? $this->renderNdjson($envelope, $export, $includeSnapshots)
            : $this->renderJson($envelope, $export, $includeSnapshots);

        $outputPath = $this->optionalOptionString('output');

        if ($outputPath !== null && trim($outputPath) !== '') {
            $written = $this->writeToFile(trim($outputPath), $rendered);

            if ($written === false) {
                return $this->failWith(sprintf('Could not write export to [%s]. Check the path and permissions, and ensure the file does not already exist (exports never overwrite).', trim($outputPath)));
            }

            $this->components->info(sprintf('Wrote %d bytes to %s', $written, trim($outputPath)));
        } else {
            $this->line($rendered);
        }

        $events->dispatch(new MemoryDumped(
            subjectType: $subjectType,
            subjectId: $id,
            format: $format,
            includeSnapshots: $includeSnapshots,
            entryCount: count($export['entries']),
            snapshotCount: count($export['snapshots']),
            runsExpanded: $export['runs_expanded'],
        ));

        $audit->emit('command.memory.dump', [
            'subject_type' => $subjectType,
            'subject_id' => $id,
            'format' => $format,
            'include_snapshots' => $includeSnapshots,
            'entry_count' => count($export['entries']),
            'snapshot_count' => count($export['snapshots']),
            'runs_expanded' => $export['runs_expanded'],
            'output' => $outputPath !== null && trim($outputPath) !== '' ? trim($outputPath) : null,
            // "who took a copy": artisan has no authenticated user, so record the
            // OS process owner and any operator-supplied reason alongside the
            // system actor so the egress record names a human where it can.
            'requested_by' => $this->resolveRequestedBy(),
            'reason' => $this->optionalOptionString('reason'),
            ...$audit->metadata(['actor' => Actor::system('artisan')->toArray()]),
        ]);

        return self::SUCCESS;
    }

    /**
     * Resolve the export subject for the given id.
     *
     * Returns `run`, `conversation`, `null` (matches neither — missing id), or
     * `ambiguous` (matches both under auto-detection). When `--as` is set the
     * caller's choice is honored, still validated against existence so a typo
     * surfaces as a missing-id error rather than an empty export.
     *
     * @return 'run'|'conversation'|'ambiguous'|null
     */
    protected function resolveSubjectType(
        string $id,
        ?string $as,
        Connection $connection,
        string $historyTable,
        MemoryStore $memory,
    ): ?string {
        $isRun = $this->runExists($id, $connection, $historyTable);
        $isConversation = $this->conversationExists($id, $memory);

        if ($as === 'run') {
            return $isRun ? 'run' : null;
        }

        if ($as === 'conversation') {
            return $isConversation ? 'conversation' : null;
        }

        if ($isRun && $isConversation) {
            return 'ambiguous';
        }

        if ($isRun) {
            return 'run';
        }

        if ($isConversation) {
            return 'conversation';
        }

        return null;
    }

    /**
     * Raw existence probe for a run-history row.
     *
     * Deliberately queries the history table directly rather than routing
     * through {@see RunHistoryStore::find()}:
     * this is a subject-resolution check, not export data (the actual entries
     * and snapshots DO route through the MemoryStore / SnapshotsMemory
     * contracts), and an audit probe must see a row whatever its lease/TTL
     * state. Coupling subject resolution to a contract's read-time row
     * filtering could hide a run from an export that should include it.
     */
    protected function runExists(string $id, Connection $connection, string $historyTable): bool
    {
        return $connection->table($historyTable)->where('run_id', $id)->exists();
    }

    protected function conversationExists(string $id, MemoryStore $memory): bool
    {
        return $memory->all(MemoryScope::Conversation, $id) !== [];
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, snapshots: array<int, MemorySnapshot>, runs_expanded: bool, resolver: class-string, skipped_runs: list<string>}
     */
    protected function buildRunExport(
        string $runId,
        MemoryStore $memory,
        SnapshotsMemory $snapshots,
        ConversationRunResolver $resolver,
    ): array {
        return [
            'entries' => array_map(
                fn (MemoryEntry $entry): array => $this->projectEntry($entry),
                $memory->all(MemoryScope::Run, $runId),
            ),
            'snapshots' => array_values($snapshots->allForRun($runId)),
            'runs_expanded' => false,
            'resolver' => $resolver::class,
            'skipped_runs' => [],
        ];
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, snapshots: array<int, MemorySnapshot>, runs_expanded: bool, resolver: class-string, skipped_runs: list<string>}
     */
    protected function buildConversationExport(
        string $conversationId,
        Connection $connection,
        string $historyTable,
        MemoryStore $memory,
        SnapshotsMemory $snapshots,
        ConversationRunResolver $resolver,
    ): array {
        $entries = array_map(
            fn (MemoryEntry $entry): array => $this->projectEntry($entry),
            $memory->all(MemoryScope::Conversation, $conversationId),
        );

        $snapshotRows = [];
        $skippedRuns = [];
        $runIds = $resolver->resolve($conversationId);

        foreach ($runIds as $runId) {
            if (! $this->runExists($runId, $connection, $historyTable)) {
                $skippedRuns[] = $runId;

                continue;
            }

            foreach ($memory->all(MemoryScope::Run, $runId) as $entry) {
                $entries[] = $this->projectEntry($entry);
            }

            foreach ($snapshots->allForRun($runId) as $snapshot) {
                $snapshotRows[] = $snapshot;
            }
        }

        return [
            'entries' => array_values($entries),
            'snapshots' => $snapshotRows,
            'runs_expanded' => $runIds !== [],
            'resolver' => $resolver::class,
            'skipped_runs' => $skippedRuns,
        ];
    }

    /**
     * Build the envelope metadata common to both output formats. Entry and
     * snapshot bodies are attached by the renderers so NDJSON can stream them
     * as discrete records.
     *
     * @param  array{entries: array<int, array<string, mixed>>, snapshots: array<int, MemorySnapshot>, runs_expanded: bool, resolver: class-string, skipped_runs: list<string>}  $export
     * @return array<string, mixed>
     */
    protected function envelope(string $subjectType, string $id, bool $includeSnapshots, array $export): array
    {
        $meta = [
            'ok' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'subject_type' => $subjectType,
            'subject_id' => $id,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'include_snapshots' => $includeSnapshots,
            'entry_count' => count($export['entries']),
            'snapshot_count' => count($export['snapshots']),
            // Declare which memory scopes the top-level `entries` array covers,
            // so a run export is as self-describing about its scope boundary as
            // the conversation export is about run expansion. A run export
            // carries only Run-scoped entries (Agent/Swarm-scoped memory keys on
            // an agent/swarm id, not a run id, and cannot be filtered by run);
            // a conversation export adds `run` only when a resolver expands it.
            'scopes_included' => $subjectType === 'run'
                ? ['run']
                : ($export['runs_expanded'] ? ['conversation', 'run'] : ['conversation']),
        ];

        if ($subjectType === 'conversation') {
            $meta['runs_expanded'] = $export['runs_expanded'];
            $meta['conversation_run_resolver'] = $export['resolver'];
            $meta['skipped_runs'] = $export['skipped_runs'];
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array{entries: array<int, array<string, mixed>>, snapshots: array<int, MemorySnapshot>, runs_expanded: bool, resolver: class-string, skipped_runs: list<string>}  $export
     */
    protected function renderJson(array $envelope, array $export, bool $includeSnapshots): string
    {
        $envelope['entries'] = $export['entries'];
        $envelope['snapshots'] = array_map(
            fn (MemorySnapshot $snapshot): array => $this->projectSnapshot($snapshot, $includeSnapshots),
            $export['snapshots'],
        );

        return $this->encode($envelope, pretty: true);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array{entries: array<int, array<string, mixed>>, snapshots: array<int, MemorySnapshot>, runs_expanded: bool, resolver: class-string, skipped_runs: list<string>}  $export
     */
    protected function renderNdjson(array $envelope, array $export, bool $includeSnapshots): string
    {
        $lines = [$this->encode(['record' => 'header'] + $envelope, pretty: false)];

        foreach ($export['entries'] as $entry) {
            $lines[] = $this->encode(['record' => 'entry'] + $entry, pretty: false);
        }

        foreach ($export['snapshots'] as $snapshot) {
            $lines[] = $this->encode(['record' => 'snapshot'] + $this->projectSnapshot($snapshot, $includeSnapshots), pretty: false);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    protected function projectEntry(MemoryEntry $entry): array
    {
        return [
            'scope' => $entry->scope->value,
            'scope_id' => $entry->scopeId,
            'key' => $entry->key,
            'value' => $entry->value,
            'metadata' => $entry->metadata,
            'created_at' => $entry->createdAt?->toIso8601String(),
            'updated_at' => $entry->updatedAt?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function projectSnapshot(MemorySnapshot $snapshot, bool $includeSnapshots): array
    {
        $reference = [
            'run_id' => $snapshot->runId,
            'step_index' => $snapshot->stepIndex,
            'recorded_at' => $snapshot->recordedAt,
            'updated_at' => $snapshot->updatedAt,
            'entry_count' => count($snapshot->entries),
            'tool_call_count' => count($snapshot->toolCalls),
        ];

        if (! $includeSnapshots) {
            return $reference;
        }

        return $reference + [
            'entries' => $snapshot->entries,
            'tool_calls' => array_values($snapshot->toolCalls),
        ];
    }

    /**
     * Write the rendered export to a file. Returns the byte count on success,
     * or false when the path is not writable.
     *
     * Opened with exclusive create (`x`): the command refuses to follow a
     * symlink or clobber an existing file. An audit export can carry unredacted
     * memory values, so it must never overwrite an unrelated file or be written
     * through a planted symlink to a world-readable location — `fopen($path,
     * 'xb')` fails outright if the path already exists. The handle is locked to
     * owner-only (0600) BEFORE the payload is written, so the export is never
     * even briefly readable by other users on a shared host.
     */
    protected function writeToFile(string $path, string $contents): int|false
    {
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            return false;
        }

        $handle = @fopen($path, 'xb');

        if ($handle === false) {
            return false;
        }

        @chmod($path, 0600);

        $written = @fwrite($handle, $contents);
        @fclose($handle);

        return $written;
    }

    /**
     * @return 'json'|'ndjson'|null
     */
    protected function resolveFormat(): ?string
    {
        $raw = $this->option('format');
        $format = is_string($raw) ? strtolower(trim($raw)) : 'json';

        if ($format === '' || $format === 'json') {
            return 'json';
        }

        if ($format === 'ndjson') {
            return 'ndjson';
        }

        return null;
    }

    /**
     * @return 'run'|'conversation'|null|false `null` means auto-detect, `false` means invalid input.
     */
    protected function resolveAs(): string|false|null
    {
        $raw = $this->option('as');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw)) {
            return false;
        }

        $value = strtolower(trim($raw));

        if ($value === 'run' || $value === 'conversation') {
            return $value;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function encode(array $payload, bool $pretty): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $encoded = json_encode($payload, $flags);

        return $encoded !== false ? $encoded : '{}';
    }

    protected function failWith(string $message): int
    {
        if ($this->resolveFormat() !== null) {
            $this->line($this->encode(['ok' => false, 'error' => $message], pretty: true));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
