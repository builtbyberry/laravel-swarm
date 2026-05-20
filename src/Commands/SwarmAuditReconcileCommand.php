<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'swarm:audit:reconcile')]
class SwarmAuditReconcileCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected ?string $reconcileAuditError = null;

    protected $signature = 'swarm:audit:reconcile
                            {--show= : Print full metadata and unsealed payload for the given outbox id.}
                            {--requeue= : Reset a dead_letter row to pending so the relay re-attempts emission.}
                            {--dismiss= : Permanently delete a dead_letter row (requires --reason).}
                            {--status= : Filter the list view by status (pending or dead_letter).}
                            {--limit=50 : Cap the list view row count.}
                            {--reason= : Operator-supplied reason recorded on the command.audit_reconcile audit record. Required for --dismiss.}
                            {--force : Skip the interactive confirmation prompt.}
                            {--json : Emit machine-readable output (works for list, show, requeue, dismiss).}';

    protected $description = 'Forensic triage for the swarm audit outbox (list, inspect, requeue, dismiss dead-letter rows)';

    protected $help = <<<'HELP'
        Operator command for the swarm_audit_outbox table. Sub-modes are mutually
        exclusive:

          (no flags)         List pending and dead_letter rows.
          --show=<id>        Print full row metadata and unsealed payload.
          --requeue=<id>     Reset a dead_letter row to pending (relay re-attempts).
          --dismiss=<id>     Permanently delete a dead_letter row. Requires --reason.

        Pending rows can be listed and shown but cannot be requeued or dismissed;
        the relay owns their lifecycle.

        Every requeue/dismiss emits a command.audit_reconcile audit record before
        mutating the row. If the audit emit fails, the row is left untouched.

        Examples:
          php artisan swarm:audit:reconcile
          php artisan swarm:audit:reconcile --status=dead_letter --limit=200
          php artisan swarm:audit:reconcile --show=42
          php artisan swarm:audit:reconcile --requeue=42 --reason="downstream sink restored"
          php artisan swarm:audit:reconcile --dismiss=42 --reason="duplicate of run.failed for r-7" --force
        HELP;

    public function handle(
        AuditOutbox $outbox,
        ConfigRepository $config,
        Connection $connection,
        SwarmPersistenceCipher $cipher,
        SwarmAuditDispatcher $audit,
    ): int {
        if (! $outbox->isAvailable()) {
            $message = 'swarm:audit:reconcile requires the database-backed audit outbox. Set swarm.persistence.driver=database and run the package migrations.';

            if ($this->option('json') === true) {
                $this->writeJson(['ok' => false, 'error' => $message]);
            } else {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $modes = array_filter([
            'show' => $this->optionalOptionString('show'),
            'requeue' => $this->optionalOptionString('requeue'),
            'dismiss' => $this->optionalOptionString('dismiss'),
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        if (count($modes) > 1) {
            return $this->failWith('Use only one of --show, --requeue, --dismiss per invocation.');
        }

        if ($modes === []) {
            return $this->runList($config, $connection);
        }

        $mode = array_key_first($modes);
        $id = $this->resolveId($modes[$mode], $mode);

        if ($id === null) {
            return self::FAILURE;
        }

        return match ($mode) {
            'show' => $this->runShow($config, $connection, $cipher, $audit, $id),
            'requeue' => $this->runRequeue($config, $connection, $audit, $id),
            'dismiss' => $this->runDismiss($config, $connection, $audit, $id),
            default => self::FAILURE,
        };
    }

    protected function runList(ConfigRepository $config, Connection $connection): int
    {
        $statusFilter = $this->optionalOptionString('status');

        if ($statusFilter !== null && ! in_array($statusFilter, ['pending', 'dead_letter'], true)) {
            return $this->failWith('Unknown --status value. Use "pending" or "dead_letter".');
        }

        $limit = $this->optionInt('limit', 50);
        $limit = max(1, min($limit, 10_000));

        $table = $this->tableName($config);
        $query = $connection->table($table);

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        } else {
            $query->whereIn('status', ['pending', 'dead_letter']);
        }

        $rows = $query->orderBy('id')->limit($limit + 1)->get();
        $truncated = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $now = Carbon::now('UTC');
        $records = $rows->map(fn (\stdClass $row): array => [
            'id' => (int) $row->id,
            'status' => (string) $row->status,
            'category' => (string) $row->category,
            'run_id' => $row->run_id !== null ? (string) $row->run_id : null,
            'attempts' => (int) $row->attempts,
            'last_attempted_at' => $this->formatTimestamp($row->last_attempted_at),
            'age' => $this->ageHuman($row->created_at, $now),
        ])->all();

        if ($this->option('json') === true) {
            $this->writeJson([
                'ok' => true,
                'count' => count($records),
                'truncated' => $truncated,
                'limit' => $limit,
                'status_filter' => $statusFilter,
                'rows' => $records,
            ]);

            return self::SUCCESS;
        }

        if ($records === []) {
            $this->components->info('No audit outbox rows match.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Status', 'Category', 'Run ID', 'Attempts', 'Last attempted', 'Age'],
            array_map(fn (array $r): array => [
                (string) $r['id'],
                $r['status'],
                $r['category'],
                $r['run_id'] ?? '-',
                (string) $r['attempts'],
                $r['last_attempted_at'] ?? '-',
                $r['age'],
            ], $records),
        );

        if ($truncated) {
            $this->components->warn("Output capped at {$limit} rows. Filter with --status or raise --limit.");
        }

        return self::SUCCESS;
    }

    protected function runShow(ConfigRepository $config, Connection $connection, SwarmPersistenceCipher $cipher, SwarmAuditDispatcher $audit, int $id): int
    {
        $row = $this->findRow($config, $connection, $id);

        if ($row === null) {
            return $this->failWith("Audit outbox row [{$id}] not found.");
        }

        $payload = $this->decodePayload($cipher, $row);
        $lastError = $this->decodeLastError($cipher, $row);

        $detail = [
            'id' => (int) $row->id,
            'status' => (string) $row->status,
            'category' => (string) $row->category,
            'run_id' => $row->run_id !== null ? (string) $row->run_id : null,
            'attempts' => (int) $row->attempts,
            'created_at' => $this->formatTimestamp($row->created_at),
            'updated_at' => $this->formatTimestamp($row->updated_at),
            'last_attempted_at' => $this->formatTimestamp($row->last_attempted_at),
            'reserved_at' => $this->formatTimestamp($row->reserved_at),
            'last_error' => $lastError,
            'payload' => $payload,
        ];

        $auditEmitted = $this->emitReconcileAudit($audit, 'show', $row, (int) $row->attempts, null);

        if ($this->option('json') === true) {
            $this->writeJson([
                'ok' => $auditEmitted,
                'row' => $detail,
                'audit_emitted' => $auditEmitted,
            ]);

            return $auditEmitted ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['ID', (string) $detail['id']],
            ['Status', $detail['status']],
            ['Category', $detail['category']],
            ['Run ID', $detail['run_id'] ?? '-'],
            ['Attempts', (string) $detail['attempts']],
            ['Created', $detail['created_at'] ?? '-'],
            ['Updated', $detail['updated_at'] ?? '-'],
            ['Last attempted', $detail['last_attempted_at'] ?? '-'],
            ['Reserved', $detail['reserved_at'] ?? '-'],
        ]);

        $this->line('');
        $this->components->info('Last error');
        $this->line($lastError ?? '-');

        $this->line('');
        $this->components->info('Payload');
        $this->line($this->prettyJson($payload));

        if (! $auditEmitted) {
            $this->components->warn('Read audit chain is broken: command.audit_reconcile emit failed. The row above was already in memory; rerun once the audit sink is healthy.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function runRequeue(ConfigRepository $config, Connection $connection, SwarmAuditDispatcher $audit, int $id): int
    {
        if (($forceFailure = $this->requireForceForJsonMutation()) !== null) {
            return $forceFailure;
        }

        $row = $this->findRow($config, $connection, $id);

        if ($row === null) {
            return $this->failWith("Audit outbox row [{$id}] not found.");
        }

        if ((string) $row->status !== 'dead_letter') {
            return $this->failWith("Audit outbox row [{$id}] has status [{$row->status}]; only dead_letter rows can be requeued. Pending rows are owned by the relay.");
        }

        $reason = $this->optionalOptionString('reason');

        if (! $this->confirmAction("Requeue audit outbox row [{$id}] (category={$row->category}, attempts={$row->attempts})?")) {
            return $this->aborted();
        }

        $priorAttempts = (int) $row->attempts;

        if (! $this->emitReconcileAudit($audit, 'requeue', $row, $priorAttempts, $reason)) {
            return $this->failWith(
                "Failed to emit command.audit_reconcile evidence: {$this->reconcileAuditError}. "
                .'The outbox row was NOT modified.',
            );
        }

        $connection->table($this->tableName($config))
            ->where('id', $id)
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'reserved_at' => null,
                'updated_at' => Carbon::now('UTC'),
            ]);

        if ($this->option('json') === true) {
            $this->writeJson([
                'ok' => true,
                'action' => 'requeue',
                'id' => $id,
                'prior_attempts' => $priorAttempts,
                'reason' => $reason,
            ]);

            return self::SUCCESS;
        }

        $this->components->info("Audit outbox row [{$id}] requeued. Status=pending, attempts=0. last_error preserved for forensics.");

        return self::SUCCESS;
    }

    protected function runDismiss(ConfigRepository $config, Connection $connection, SwarmAuditDispatcher $audit, int $id): int
    {
        if (($forceFailure = $this->requireForceForJsonMutation()) !== null) {
            return $forceFailure;
        }

        $reason = $this->optionalOptionString('reason');

        if ($reason === null || trim($reason) === '') {
            return $this->failWith('--dismiss requires --reason="<text>". Audit evidence cannot be discarded without a chain-of-custody reason.');
        }

        $row = $this->findRow($config, $connection, $id);

        if ($row === null) {
            return $this->failWith("Audit outbox row [{$id}] not found.");
        }

        if ((string) $row->status !== 'dead_letter') {
            return $this->failWith("Audit outbox row [{$id}] has status [{$row->status}]; only dead_letter rows can be dismissed. Pending rows are owned by the relay.");
        }

        if (! $this->confirmAction("Permanently delete audit outbox row [{$id}] (category={$row->category}, run_id=".($row->run_id ?? '-').')?')) {
            return $this->aborted();
        }

        $priorAttempts = (int) $row->attempts;

        if (! $this->emitReconcileAudit($audit, 'dismiss', $row, $priorAttempts, $reason)) {
            return $this->failWith(
                "Failed to emit command.audit_reconcile evidence: {$this->reconcileAuditError}. "
                .'The outbox row was NOT modified.',
            );
        }

        $connection->table($this->tableName($config))->where('id', $id)->delete();

        if ($this->option('json') === true) {
            $this->writeJson([
                'ok' => true,
                'action' => 'dismiss',
                'id' => $id,
                'prior_attempts' => $priorAttempts,
                'reason' => $reason,
            ]);

            return self::SUCCESS;
        }

        $this->components->info("Audit outbox row [{$id}] dismissed. A command.audit_reconcile evidence record preserves the deletion.");

        return self::SUCCESS;
    }

    protected function emitReconcileAudit(SwarmAuditDispatcher $audit, string $action, \stdClass $row, int $priorAttempts, ?string $reason): bool
    {
        $this->reconcileAuditError = null;

        $actorMetadata = ['actor' => Actor::system('artisan')->toArray()];
        $now = Carbon::now('UTC');

        $payload = [
            'action' => $action,
            'target_id' => (int) $row->id,
            'target_category' => (string) $row->category,
            'target_run_id' => $row->run_id !== null ? (string) $row->run_id : null,
            'prior_attempts' => $priorAttempts,
            'target_created_at' => $this->formatTimestamp($row->created_at),
            'target_age_seconds' => $this->ageSeconds($row->created_at, $now),
        ];

        if ($action !== 'show') {
            $payload['reason'] = $reason;
        }

        if ($action === 'dismiss') {
            $payload['target_payload_digest'] = hash('sha256', is_string($row->payload) ? $row->payload : '');
        }

        $payload = [...$payload, ...$audit->metadata($actorMetadata)];

        try {
            $audit->emit('command.audit_reconcile', $payload);

            return true;
        } catch (Throwable $exception) {
            $this->reconcileAuditError = $exception->getMessage();

            return false;
        }
    }

    protected function confirmAction(string $prompt): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if (! $this->input->isInteractive() || $this->option('json') === true) {
            return false;
        }

        return $this->confirm($prompt, false);
    }

    protected function requireForceForJsonMutation(): ?int
    {
        if ($this->option('json') !== true || $this->option('force') === true) {
            return null;
        }

        $message = 'Non-interactive automation requires --force to confirm requeue/dismiss.';
        $this->writeJson(['ok' => false, 'error' => 'force_required', 'message' => $message]);

        return self::FAILURE;
    }

    protected function findRow(ConfigRepository $config, Connection $connection, int $id): ?\stdClass
    {
        $row = $connection->table($this->tableName($config))->where('id', $id)->first();

        return $row instanceof \stdClass ? $row : null;
    }

    /**
     * @return array<string, mixed>|string|null
     */
    protected function decodePayload(SwarmPersistenceCipher $cipher, \stdClass $row): array|string|null
    {
        $raw = is_string($row->payload) ? $cipher->open($row->payload) : null;

        if ($raw === null) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $raw;
        }

        return is_array($decoded) ? $decoded : $raw;
    }

    protected function decodeLastError(SwarmPersistenceCipher $cipher, \stdClass $row): ?string
    {
        if (! is_string($row->last_error) || $row->last_error === '') {
            return null;
        }

        return $cipher->open($row->last_error);
    }

    protected function resolveId(string $raw, string $mode): ?int
    {
        if (! is_numeric($raw) || (int) $raw < 1) {
            $this->failWith("--{$mode} must be a positive integer id.");

            return null;
        }

        return (int) $raw;
    }

    protected function tableName(ConfigRepository $config): string
    {
        return (string) $config->get('swarm.tables.audit_outbox', 'swarm_audit_outbox');
    }

    protected function formatTimestamp(mixed $value): ?string
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

    protected function ageHuman(mixed $createdAt, Carbon $now): string
    {
        $seconds = $this->ageSeconds($createdAt, $now);

        if ($seconds === null) {
            return '-';
        }

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            return floor($seconds / 60).'m';
        }

        if ($seconds < 86400) {
            return floor($seconds / 3600).'h';
        }

        return floor($seconds / 86400).'d';
    }

    protected function ageSeconds(mixed $createdAt, Carbon $now): ?int
    {
        if ($createdAt === null || $createdAt === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse((string) $createdAt, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return max(0, (int) abs($now->diffInSeconds($parsed, false)));
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

    protected function aborted(): int
    {
        if ($this->option('json') === true) {
            $this->writeJson(['ok' => false, 'error' => 'aborted by operator']);
        } else {
            $this->components->warn('Aborted.');
        }

        return self::FAILURE;
    }
}
