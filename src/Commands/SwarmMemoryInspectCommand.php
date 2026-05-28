<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionResolverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use ValueError;

/**
 * Operator-facing inspector for `swarm_memory_snapshots`.
 *
 * Every runner — sequential, parallel, hierarchical, and durable branch —
 * freezes the agent-visible memory view into the `swarm_memory_snapshots`
 * table before invoking an agent. Without this command those frozen rows
 * are effectively invisible to humans: the only access path is raw SQL or
 * a `SnapshotsMemory::find()` call from application code. This command is
 * the audit / debugging primitive that pairs with `swarm:memory:dump`
 * (full-run export, issue #123).
 *
 * Reads the snapshot row directly via the configured table name so the
 * command works against any driver that backs the contract — it does not
 * special-case `DatabaseMemorySnapshotRecorder` and does not need a runner
 * to be active. When persistence is in `cache` mode the table is absent
 * and the command surfaces a clear configuration error rather than 500ing.
 */
#[AsCommand(name: 'swarm:memory:inspect')]
class SwarmMemoryInspectCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:memory:inspect
                            {run_id : The run identifier to inspect snapshots for.}
                            {--step= : Show the full snapshot for this step index. Omit to list every step recorded for the run.}
                            {--format=table : Output format. One of: table, json.}
                            {--scope= : Filter snapshot entries to a single MemoryScope. One of: run, conversation, agent, swarm. Default: all scopes.}';

    protected $description = 'Inspect frozen MemorySnapshot rows for a swarm run — exactly what the agent saw at invocation time.';

    protected $help = <<<'HELP'
        Reads the frozen memory snapshot rows persisted by every Swarm runner
        (sequential, parallel, hierarchical, durable branch) into
        `swarm_memory_snapshots`. The natural key is (run_id, step_index).

        Default behavior lists one row per step recorded for the run:
        step index, entry count, tool-call count, recorded-at timestamp.

        Pass --step=N to expand the snapshot for a single step: the agent-
        visible memory entries plus every tool-call input/output pair the
        runner recorded during the invocation.

        --scope filters the entries view to a single MemoryScope. Note that
        v0.9.0 snapshots freeze MemoryScope::Run only; other scopes are
        accepted as filters but will return empty entry lists for snapshots
        captured under that contract.

        --format=json emits a machine-readable envelope suitable for piping
        into jq or storing as evidence.

        Examples:
          php artisan swarm:memory:inspect r-abc123
          php artisan swarm:memory:inspect r-abc123 --step=0
          php artisan swarm:memory:inspect r-abc123 --step=2 --format=json
          php artisan swarm:memory:inspect r-abc123 --scope=run --format=json
        HELP;

    public function handle(
        ConfigRepository $config,
        ConnectionResolverInterface $connectionResolver,
    ): int {
        $runId = trim($this->argumentString('run_id'));

        if ($runId === '') {
            return $this->failWith('run_id argument is required.');
        }

        $format = $this->resolveFormat();

        if ($format === null) {
            return $this->failWith('--format must be one of: table, json.');
        }

        $scope = $this->resolveScope();

        if ($scope === false) {
            return $this->failWith('--scope must be one of: run, conversation, agent, swarm.');
        }

        $step = $this->resolveStep();

        if ($step === false) {
            return $this->failWith('--step must be a non-negative integer.');
        }

        $connection = $connectionResolver->connection();
        $table = (string) $config->get('swarm.tables.memory_snapshots', 'swarm_memory_snapshots');

        $query = $connection->table($table)
            ->where('run_id', $runId)
            ->orderBy('step_index');

        if ($step !== null) {
            $query = $query->where('step_index', $step);
        }

        try {
            $rows = $query->get();
        } catch (Throwable $exception) {
            return $this->failWith(sprintf(
                'Could not read %s: %s. Ensure swarm.persistence.driver=database and the memory-snapshots migration has run.',
                $table,
                $exception->getMessage(),
            ));
        }

        if ($rows->isEmpty()) {
            $message = $step !== null
                ? sprintf('No snapshot found for run_id=%s step=%d.', $runId, $step)
                : sprintf('No snapshots found for run_id=%s.', $runId);

            return $this->failWith($message);
        }

        $snapshots = [];
        foreach ($rows as $row) {
            $snapshots[] = $this->normalizeRow($row, $scope);
        }

        if ($step !== null) {
            return $this->renderSingle($snapshots[0], $format, $scope);
        }

        return $this->renderList($runId, $snapshots, $format, $scope);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function renderSingle(array $snapshot, string $format, ?MemoryScope $scope): int
    {
        if ($format === 'json') {
            $this->writeJson([
                'ok' => true,
                'run_id' => $snapshot['run_id'],
                'step_index' => $snapshot['step_index'],
                'scope_filter' => $scope?->value,
                'recorded_at' => $snapshot['recorded_at'],
                'updated_at' => $snapshot['updated_at'],
                'entry_count' => count($snapshot['entries']),
                'tool_call_count' => count($snapshot['tool_calls']),
                'entries' => $snapshot['entries'],
                'tool_calls' => $snapshot['tool_calls'],
            ]);

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Memory snapshot for run [%s] step [%d]',
            $snapshot['run_id'],
            $snapshot['step_index'],
        ));

        $this->components->twoColumnDetail('recorded at', $snapshot['recorded_at'] ?? '-');
        $this->components->twoColumnDetail('updated at', $snapshot['updated_at'] ?? '-');
        $this->components->twoColumnDetail('scope filter', $scope !== null ? $scope->value : '(all)');
        $this->components->twoColumnDetail('entries', (string) count($snapshot['entries']));
        $this->components->twoColumnDetail('tool calls', (string) count($snapshot['tool_calls']));

        $this->line('');
        $this->components->info('Entries');

        if ($snapshot['entries'] === []) {
            $this->components->warn('No entries captured at this snapshot.');
        } else {
            $rows = array_map(static fn (array $entry): array => [
                (string) ($entry['scope'] ?? '-'),
                (string) ($entry['scope_id'] ?? '-'),
                (string) ($entry['key'] ?? '-'),
                self::truncateForTable(self::prettyOneLine($entry['value'] ?? null), 60),
                (string) ($entry['updated_at'] ?? $entry['created_at'] ?? '-'),
            ], $snapshot['entries']);

            $this->table(['Scope', 'Scope ID', 'Key', 'Value', 'Updated At'], $rows);
        }

        $this->line('');
        $this->components->info('Tool calls');

        if ($snapshot['tool_calls'] === []) {
            $this->components->warn('No tool calls recorded for this invocation.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($snapshot['tool_calls'] as $index => $call) {
            $rows[] = [
                (string) $index,
                (string) ($call['name'] ?? '-'),
                self::truncateForTable(self::prettyOneLine($call['arguments'] ?? null), 40),
                self::truncateForTable(self::prettyOneLine($call['result'] ?? null), 40),
                (string) ($call['id'] ?? '-'),
            ];
        }

        $this->table(['#', 'Name', 'Arguments', 'Result', 'Call ID'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     */
    protected function renderList(string $runId, array $snapshots, string $format, ?MemoryScope $scope): int
    {
        if ($format === 'json') {
            $this->writeJson([
                'ok' => true,
                'run_id' => $runId,
                'scope_filter' => $scope?->value,
                'snapshot_count' => count($snapshots),
                'snapshots' => array_map(static fn (array $snapshot): array => [
                    'step_index' => $snapshot['step_index'],
                    'recorded_at' => $snapshot['recorded_at'],
                    'updated_at' => $snapshot['updated_at'],
                    'entry_count' => count($snapshot['entries']),
                    'tool_call_count' => count($snapshot['tool_calls']),
                ], $snapshots),
            ]);

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Memory snapshots for run [%s]', $runId));

        if ($scope !== null) {
            $this->components->twoColumnDetail('scope filter', $scope->value);
        }

        $rows = array_map(static fn (array $snapshot): array => [
            (string) $snapshot['step_index'],
            (string) count($snapshot['entries']),
            (string) count($snapshot['tool_calls']),
            (string) ($snapshot['recorded_at'] ?? '-'),
            (string) ($snapshot['updated_at'] ?? '-'),
        ], $snapshots);

        $this->table(['Step', 'Entries', 'Tool Calls', 'Recorded At', 'Updated At'], $rows);

        $this->line('');
        $this->components->info(sprintf(
            'Use --step=N to inspect a single snapshot in detail. %d snapshot(s) shown.',
            count($snapshots),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeRow(object $row, ?MemoryScope $scope): array
    {
        $payload = $this->decodeJson($row->payload ?? null);
        $toolCalls = $this->decodeJson($row->tool_calls ?? null);

        /** @var array<int, array<string, mixed>> $entries */
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];

        if ($scope !== null) {
            $entries = array_values(array_filter(
                $entries,
                static fn (mixed $entry): bool => is_array($entry)
                    && (($entry['scope'] ?? null) === $scope->value),
            ));
        }

        return [
            'run_id' => (string) ($payload['run_id'] ?? $row->run_id ?? ''),
            'step_index' => (int) ($payload['step_index'] ?? $row->step_index ?? 0),
            'entries' => $entries,
            'tool_calls' => array_values($toolCalls),
            'recorded_at' => isset($row->created_at) ? (string) $row->created_at : null,
            'updated_at' => isset($row->updated_at) ? (string) $row->updated_at : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    protected function resolveFormat(): ?string
    {
        $raw = $this->option('format');
        $format = is_string($raw) ? strtolower(trim($raw)) : 'table';

        if ($format === '' || $format === 'table') {
            return 'table';
        }

        if ($format === 'json') {
            return 'json';
        }

        return null;
    }

    /**
     * @return MemoryScope|null|false `null` means "no filter", `false` means invalid input.
     */
    protected function resolveScope(): MemoryScope|false|null
    {
        $raw = $this->option('scope');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw)) {
            return false;
        }

        try {
            return MemoryScope::from(strtolower(trim($raw)));
        } catch (ValueError) {
            return false;
        }
    }

    /**
     * @return int|null|false `null` means "no step filter", `false` means invalid input.
     */
    protected function resolveStep(): int|false|null
    {
        $raw = $this->option('step');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_numeric($raw)) {
            return false;
        }

        $step = (int) $raw;

        if ($step < 0 || (string) $step !== (string) $raw) {
            return false;
        }

        return $step;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function writeJson(array $payload): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->line($encoded !== false ? $encoded : '{}');
    }

    protected function failWith(string $message): int
    {
        if ($this->resolveFormat() === 'json') {
            $this->writeJson(['ok' => false, 'error' => $message]);
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }

    protected static function prettyOneLine(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value) || $value === null) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded !== false ? $encoded : '';
    }

    protected static function truncateForTable(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }
}
