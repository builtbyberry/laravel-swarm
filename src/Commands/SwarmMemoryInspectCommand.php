<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryInspected;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Memory\NullSnapshotsMemory;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
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
 * Reads route through the {@see SnapshotsMemory} contract so any driver
 * binding the host application chooses to use is honored. When persistence
 * is in `cache` mode the resolved binding is {@see NullSnapshotsMemory},
 * and the command surfaces a clear configuration error rather than 500ing.
 */
#[AsCommand(name: 'swarm:memory:inspect')]
class SwarmMemoryInspectCommand extends Command
{
    use ResolvesStringConsoleInput;

    /**
     * Character budget for a single rendered table cell before truncation.
     * Bumped from the original 40 to give the operator more signal in the
     * default view; the `--format=json` hint surfaces when any cell hits the
     * cap so the operator knows to switch formats for the full value.
     */
    protected const TABLE_CELL_BUDGET = 60;

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
        SnapshotsMemory $snapshots,
        Dispatcher $events,
        SwarmAuditDispatcher $audit,
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

        // Diagnostic fallback for the cache-driver path: the resolved
        // binding is the no-op `NullSnapshotsMemory`, which silently returns
        // empty lookups. Surface the configuration hint instead of telling
        // the operator "no snapshots found" — that would be misleading.
        if ($snapshots instanceof NullSnapshotsMemory) {
            return $this->failWith(sprintf(
                'Could not read %s. Ensure swarm.persistence.driver=database and the memory-snapshots migration has run.',
                (string) $config->get('swarm.tables.memory_snapshots', 'swarm_memory_snapshots'),
            ));
        }

        if ($step !== null) {
            $snapshot = $snapshots->find($runId, $step);
            $found = $snapshot !== null ? [$snapshot] : [];
        } else {
            $found = $snapshots->allForRun($runId);
        }

        if ($found === []) {
            $message = $step !== null
                ? sprintf('No snapshot found for run_id=%s step=%d.', $runId, $step)
                : sprintf('No snapshots found for run_id=%s.', $runId);

            return $this->failWith($message);
        }

        $projected = array_map(
            fn (MemorySnapshot $snapshot): array => $this->projectSnapshot($snapshot, $scope),
            $found,
        );

        $events->dispatch(new MemoryInspected(
            runId: $runId,
            stepIndex: $step,
            scopeFilter: $scope,
            format: $format,
            snapshotCount: count($projected),
        ));

        $audit->emit('command.memory.inspect', [
            'run_id' => $runId,
            'step_index' => $step,
            'scope_filter' => $scope?->value,
            'format' => $format,
            'snapshot_count' => count($projected),
            ...$audit->metadata(['actor' => Actor::system('artisan')->toArray()]),
        ]);

        if ($step !== null) {
            return $this->renderSingle($projected[0], $format, $scope);
        }

        return $this->renderList($runId, $projected, $format, $scope);
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

        $truncated = false;

        if ($snapshot['entries'] === []) {
            $this->components->warn('No entries captured at this snapshot.');
        } else {
            $rows = [];
            foreach ($snapshot['entries'] as $entry) {
                $rendered = self::prettyOneLine($entry['value'] ?? null);
                $cell = self::truncateForTable($rendered, self::TABLE_CELL_BUDGET);
                $truncated = $truncated || $cell !== $rendered;

                $rows[] = [
                    (string) ($entry['scope'] ?? '-'),
                    (string) ($entry['scope_id'] ?? '-'),
                    (string) ($entry['key'] ?? '-'),
                    $cell,
                    (string) ($entry['updated_at'] ?? $entry['created_at'] ?? '-'),
                ];
            }

            $this->table(['Scope', 'Scope ID', 'Key', 'Value', 'Updated At'], $rows);
        }

        $this->line('');
        $this->components->info('Tool calls');

        if ($snapshot['tool_calls'] === []) {
            $this->components->warn('No tool calls recorded for this invocation.');

            if ($truncated) {
                $this->emitTruncationHint();
            }

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($snapshot['tool_calls'] as $index => $call) {
            $args = self::prettyOneLine($call['arguments'] ?? null);
            $result = self::prettyOneLine($call['result'] ?? null);

            $argsCell = self::truncateForTable($args, self::TABLE_CELL_BUDGET);
            $resultCell = self::truncateForTable($result, self::TABLE_CELL_BUDGET);

            $truncated = $truncated || $argsCell !== $args || $resultCell !== $result;

            $rows[] = [
                (string) $index,
                (string) ($call['name'] ?? '-'),
                $argsCell,
                $resultCell,
                (string) ($call['id'] ?? '-'),
            ];
        }

        $this->table(['#', 'Name', 'Arguments', 'Result', 'Call ID'], $rows);

        if ($truncated) {
            $this->emitTruncationHint();
        }

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
     * Project a `MemorySnapshot` value object into the array shape the
     * renderers consume.
     *
     * @return array<string, mixed>
     */
    protected function projectSnapshot(MemorySnapshot $snapshot, ?MemoryScope $scope): array
    {
        $entries = $snapshot->entries;

        if ($scope !== null) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['scope'] === $scope->value,
            ));
        }

        return [
            'run_id' => $snapshot->runId,
            'step_index' => $snapshot->stepIndex,
            'entries' => $entries,
            'tool_calls' => array_values($snapshot->toolCalls),
            // Persisted row timestamps when the snapshot was hydrated from
            // storage; null for drivers that record no per-row timestamps
            // (e.g. the cache-mode null driver). The renderers tolerate null —
            // `-` in table mode, pass-through in JSON.
            'recorded_at' => $snapshot->recordedAt,
            'updated_at' => $snapshot->updatedAt,
        ];
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

    protected function emitTruncationHint(): void
    {
        $this->components->info(sprintf(
            'Some cells were truncated to %d characters. Re-run with --format=json for full values.',
            self::TABLE_CELL_BUDGET,
        ));
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
