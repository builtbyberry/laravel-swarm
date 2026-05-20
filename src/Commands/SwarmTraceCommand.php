<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'swarm:trace')]
class SwarmTraceCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:trace
                            {run_id : The run identifier to reconstruct the audit chain for.}
                            {--json : Emit a machine-readable timeline (mirrors swarm:audit:status / swarm:audit:reconcile JSON shape).}
                            {--include-payloads : Include the full evidence envelope per record (off by default — payloads can be large).}
                            {--limit=1000 : Maximum number of sink-side records to consume from ReadableSwarmAuditSink::forRun(); guards against unbounded reads on long-lived runs. Outbox and history rows are bounded by the run itself and are not subject to this limit.}';

    protected $description = 'Read-only audit-chain reconstruction for a single run — merges history, outbox, and sink-side records into a chronological timeline.';

    protected $help = <<<'HELP'
        Reconstructs the audit chain for a single run by merging three sources:

          - swarm_run_histories / RunHistoryStore: the run's start, steps, finish.
          - swarm_audit_outbox: pending and dead_letter audit evidence rows.
          - Bound SwarmAuditSink: records the sink already emitted, if the sink
            implements the ReadableSwarmAuditSink contract.

        The command is read-only. It never mutates audit state.

        If the bound sink does NOT implement ReadableSwarmAuditSink, the timeline
        renders history + outbox only with a clear note about the limitation.
        If the bound sink is NoOpSwarmAuditSink, the same note explains that the
        default discarding sink cannot supply sink-side records.

        Examples:
          php artisan swarm:trace r-abc123
          php artisan swarm:trace r-abc123 --json
          php artisan swarm:trace r-abc123 --include-payloads
          php artisan swarm:trace r-abc123 --limit=5000
        HELP;

    public function handle(
        ConfigRepository $config,
        Connection $connection,
        SwarmPersistenceCipher $cipher,
        RunHistoryStore $history,
        AuditOutbox $outbox,
        SwarmAuditSink $sink,
    ): int {
        $runId = trim($this->argumentString('run_id'));

        if ($runId === '') {
            return $this->failWith('run_id argument is required.');
        }

        $includePayloads = $this->option('include-payloads') === true;
        $limit = $this->resolveSinkLimit();

        if ($limit === null) {
            return $this->failWith('--limit must be a positive integer.');
        }

        $sinkReadability = $this->classifySink($sink);

        $sinkRecords = [];
        $sinkError = null;
        $sinkTruncated = false;

        if ($sinkReadability['readable'] && $sink instanceof ReadableSwarmAuditSink) {
            try {
                foreach ($sink->forRun($runId) as $record) {
                    if (! is_array($record)) {
                        continue;
                    }

                    if (count($sinkRecords) >= $limit) {
                        $sinkTruncated = true;

                        break;
                    }

                    $sinkRecords[] = $this->normalizeSinkRecord($record);
                }
            } catch (Throwable $exception) {
                $sinkError = $exception->getMessage();
            }
        }

        $outboxAvailable = $outbox->isAvailable();
        $outboxRecords = $outboxAvailable
            ? $this->collectOutboxRecords($config, $connection, $cipher, $runId)
            : [];

        $historyRecord = $history->find($runId);
        $historyTimelineRecords = $historyRecord !== null
            ? $this->collectHistoryRecords($historyRecord, $runId)
            : [];

        $records = array_merge($sinkRecords, $outboxRecords, $historyTimelineRecords);
        usort($records, function (array $a, array $b): int {
            $left = $a['occurred_at'] ?? null;
            $right = $b['occurred_at'] ?? null;

            if ($left === $right) {
                return 0;
            }

            if ($left === null) {
                return 1;
            }

            if ($right === null) {
                return -1;
            }

            return strcmp((string) $left, (string) $right);
        });

        $degraded = ! $sinkReadability['readable'];

        $notes = [];

        if ($sinkReadability['reason'] === 'noop') {
            $notes[] = 'Bound SwarmAuditSink is NoOpSwarmAuditSink — sink-side records are not available. Bind a real SwarmAuditSink (and have it implement ReadableSwarmAuditSink) to surface emitted evidence in the trace.';
        } elseif ($sinkReadability['reason'] === 'not_readable') {
            $notes[] = sprintf(
                'Bound SwarmAuditSink (%s) does not implement ReadableSwarmAuditSink — sink-side records are not available. Implement the contract on your sink to participate in swarm:trace.',
                $sinkReadability['sink_class'],
            );
        }

        if (! $outboxAvailable) {
            $notes[] = 'Audit outbox is unavailable on the current persistence driver — outbox rows are not in the trace. Set swarm.persistence.driver=database to enable the outbox lane.';
        }

        if ($sinkError !== null) {
            $notes[] = "ReadableSwarmAuditSink::forRun() threw — sink-side records are partial or empty. Error: {$sinkError}";
        }

        if ($sinkTruncated) {
            $notes[] = sprintf(
                'Sink returned more than --limit=%d records; sink-side records were truncated. Pass a higher --limit if needed.',
                $limit,
            );
        }

        if ($historyRecord === null) {
            $notes[] = "No run history record found for run_id={$runId}. The run may have been pruned, never started, or the history store is on a different driver than expected.";
        }

        $summary = [
            'ok' => true,
            'run_id' => $runId,
            'record_count' => count($records),
            'sources' => [
                'sink' => [
                    'readable' => $sinkReadability['readable'],
                    'reason' => $sinkReadability['reason'],
                    'sink_class' => $sinkReadability['sink_class'],
                    'record_count' => count($sinkRecords),
                    'limit' => $limit,
                    'truncated' => $sinkTruncated,
                ],
                'outbox' => [
                    'available' => $outboxAvailable,
                    'record_count' => count($outboxRecords),
                ],
                'history' => [
                    'available' => $historyRecord !== null,
                    'record_count' => count($historyTimelineRecords),
                ],
            ],
            'degraded' => $degraded,
            'include_payloads' => $includePayloads,
            'notes' => $notes,
            'records' => $this->prepareRecordsForOutput($records, $includePayloads),
        ];

        if ($this->option('json') === true) {
            $this->writeJson($summary);

            return self::SUCCESS;
        }

        $this->renderHuman($summary);

        return self::SUCCESS;
    }

    /**
     * Resolve --limit to a positive int. Returns null on invalid input
     * (so handle() can fail fast with a clear error).
     */
    protected function resolveSinkLimit(): ?int
    {
        $raw = $this->option('limit');

        if (! is_numeric($raw)) {
            return null;
        }

        $limit = (int) $raw;

        return $limit > 0 ? $limit : null;
    }

    /**
     * @return array{readable: bool, reason: string, sink_class: string}
     */
    protected function classifySink(SwarmAuditSink $sink): array
    {
        $class = $sink::class;

        if ($sink instanceof NoOpSwarmAuditSink) {
            return [
                'readable' => false,
                'reason' => 'noop',
                'sink_class' => $class,
            ];
        }

        if ($sink instanceof ReadableSwarmAuditSink) {
            return [
                'readable' => true,
                'reason' => 'readable',
                'sink_class' => $class,
            ];
        }

        return [
            'readable' => false,
            'reason' => 'not_readable',
            'sink_class' => $class,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function normalizeSinkRecord(array $record): array
    {
        $category = isset($record['category']) ? (string) $record['category'] : 'unknown';
        $rawPayload = $record['payload'] ?? null;

        // Sinks may flatten the envelope at the top level. Treat the whole
        // record as the payload in that case.
        $payload = is_array($rawPayload) ? $rawPayload : $record;

        $occurredAt = $record['occurred_at'] ?? ($payload['occurred_at'] ?? null);

        return [
            'source' => 'sink',
            'category' => $category,
            'occurred_at' => $occurredAt !== null ? (string) $occurredAt : null,
            'status' => 'emitted',
            'attempts' => 1,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function collectOutboxRecords(ConfigRepository $config, Connection $connection, SwarmPersistenceCipher $cipher, string $runId): array
    {
        $table = (string) $config->get('swarm.tables.audit_outbox', 'swarm_audit_outbox');

        $rows = $connection->table($table)
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get();

        $records = [];

        foreach ($rows as $row) {
            $payload = $this->decodeOutboxPayload($cipher, $row);
            $occurredAt = is_array($payload) && isset($payload['occurred_at'])
                ? (string) $payload['occurred_at']
                : $this->isoTimestamp($row->created_at);

            $records[] = [
                'source' => 'outbox',
                'category' => (string) $row->category,
                'occurred_at' => $occurredAt,
                'status' => (string) $row->status,
                'attempts' => (int) $row->attempts,
                'outbox_id' => (int) $row->id,
                'created_at' => $this->isoTimestamp($row->created_at),
                'last_attempted_at' => $this->isoTimestamp($row->last_attempted_at),
                'last_error' => $this->decodeOutboxError($cipher, $row),
                'payload' => is_array($payload) ? $payload : null,
            ];
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $historyRecord
     * @return array<int, array<string, mixed>>
     */
    protected function collectHistoryRecords(array $historyRecord, string $runId): array
    {
        $records = [];

        $startedAt = $this->isoTimestamp($historyRecord['started_at'] ?? null);
        $finishedAt = $this->isoTimestamp($historyRecord['finished_at'] ?? null);
        $status = (string) ($historyRecord['status'] ?? 'unknown');

        $records[] = [
            'source' => 'history',
            'category' => 'history.started',
            'occurred_at' => $startedAt,
            'status' => $status,
            'attempts' => 1,
            'history_field' => 'started_at',
            'payload' => [
                'run_id' => $runId,
                'swarm_class' => $historyRecord['swarm_class'] ?? null,
                'topology' => $historyRecord['topology'] ?? null,
                'status' => $status,
                'started_at' => $startedAt,
            ],
        ];

        $steps = is_array($historyRecord['steps'] ?? null) ? $historyRecord['steps'] : [];

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $stepOccurredAt = $this->isoTimestamp(
                $step['recorded_at']
                ?? $step['completed_at']
                ?? $step['metadata']['recorded_at']
                ?? $step['metadata']['completed_at']
                ?? null,
            );

            // Fall back to the run start if the step row has no timestamp; this
            // keeps the timeline sortable even when older history rows lack
            // per-step time fields.
            if ($stepOccurredAt === null) {
                $stepOccurredAt = $startedAt;
            }

            $records[] = [
                'source' => 'history',
                'category' => 'history.step',
                'occurred_at' => $stepOccurredAt,
                'status' => 'recorded',
                'attempts' => 1,
                'history_field' => "steps[{$index}]",
                'payload' => [
                    'run_id' => $runId,
                    'step_index' => $step['metadata']['index'] ?? $index,
                    'agent_class' => $step['agent_class'] ?? null,
                ],
            ];
        }

        if ($finishedAt !== null) {
            $records[] = [
                'source' => 'history',
                'category' => 'history.finished',
                'occurred_at' => $finishedAt,
                'status' => $status,
                'attempts' => 1,
                'history_field' => 'finished_at',
                'payload' => [
                    'run_id' => $runId,
                    'status' => $status,
                    'finished_at' => $finishedAt,
                    'error' => $historyRecord['error'] ?? null,
                ],
            ];
        }

        return $records;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeOutboxPayload(SwarmPersistenceCipher $cipher, \stdClass $row): ?array
    {
        if (! is_string($row->payload) || $row->payload === '') {
            return null;
        }

        $raw = $cipher->open($row->payload);

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    protected function decodeOutboxError(SwarmPersistenceCipher $cipher, \stdClass $row): ?string
    {
        if (! isset($row->last_error) || ! is_string($row->last_error) || $row->last_error === '') {
            return null;
        }

        return $cipher->open($row->last_error);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    protected function prepareRecordsForOutput(array $records, bool $includePayloads): array
    {
        $prepared = [];

        foreach ($records as $record) {
            if (! $includePayloads) {
                unset($record['payload']);
            }

            $prepared[] = $record;
        }

        return $prepared;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function renderHuman(array $summary): void
    {
        $this->components->info("Audit chain trace for run [{$summary['run_id']}]");

        $sources = $summary['sources'];
        $this->components->twoColumnDetail('records', (string) $summary['record_count']);
        $this->components->twoColumnDetail('sink readable', $sources['sink']['readable'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('sink class', $sources['sink']['sink_class']);
        $this->components->twoColumnDetail('sink record count', (string) $sources['sink']['record_count']);
        $this->components->twoColumnDetail('outbox available', $sources['outbox']['available'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('outbox record count', (string) $sources['outbox']['record_count']);
        $this->components->twoColumnDetail('history available', $sources['history']['available'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('history record count', (string) $sources['history']['record_count']);

        if ($summary['notes'] !== []) {
            $this->line('');
            $this->components->info('Notes');
            $this->components->bulletList($summary['notes']);
        }

        if ($summary['records'] === []) {
            $this->line('');
            $this->components->warn('No records found.');

            return;
        }

        $this->line('');
        $this->components->info('Timeline');

        $rows = array_map(function (array $record): array {
            return [
                $record['occurred_at'] ?? '-',
                (string) ($record['source'] ?? '-'),
                (string) ($record['category'] ?? '-'),
                (string) ($record['status'] ?? '-'),
                (string) ($record['attempts'] ?? 1),
                $this->detailColumn($record),
            ];
        }, $summary['records']);

        $this->table(
            ['Occurred at', 'Source', 'Category', 'Status', 'Attempts', 'Detail'],
            $rows,
        );

        if ($summary['include_payloads']) {
            $this->line('');
            $this->components->info('Payloads');

            foreach ($summary['records'] as $i => $record) {
                $occurred = $record['occurred_at'] ?? '-';
                $category = $record['category'] ?? '-';
                $this->line("[{$i}] {$occurred} {$category}");
                $this->line($this->prettyJson($record['payload'] ?? null));
                $this->line('');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function detailColumn(array $record): string
    {
        $source = (string) ($record['source'] ?? '-');

        if ($source === 'outbox') {
            $parts = ['id='.($record['outbox_id'] ?? '-')];

            $lastError = $record['last_error'] ?? null;

            if (is_string($lastError) && $lastError !== '') {
                $parts[] = 'last_error='.$this->truncate($lastError, 60);
            }

            return implode(' ', $parts);
        }

        if ($source === 'history') {
            return (string) ($record['history_field'] ?? '-');
        }

        return '-';
    }

    protected function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }

    protected function isoTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value, 'UTC')->toIso8601String();
        } catch (Throwable) {
            return is_scalar($value) ? (string) $value : null;
        }
    }

    protected function prettyJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded !== false ? $encoded : '{}';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function writeJson(array $payload): void
    {
        $this->line($this->prettyJson($payload));
    }

    protected function failWith(string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson(['ok' => false, 'error' => $message]);
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
