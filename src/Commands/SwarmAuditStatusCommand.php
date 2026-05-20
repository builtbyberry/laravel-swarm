<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:audit:status')]
class SwarmAuditStatusCommand extends Command
{
    protected $signature = 'swarm:audit:status
                            {--json : Output machine-readable summary results}';

    protected $description = 'Summarize the audit outbox — counts, age distribution, dead-letter categories, and retention';

    public function handle(AuditOutbox $outbox, ConfigRepository $config, Connection $connection): int
    {
        if (! $outbox->isAvailable()) {
            return $this->renderUnavailable();
        }

        $outboxTable = (string) $config->get('swarm.tables.audit_outbox', 'swarm_audit_outbox');
        $reservationTimeoutSeconds = (int) $config->get('swarm.durable.relay.reservation_timeout_seconds', 60);
        $retentionDays = $config->get('swarm.audit.outbox.dead_letter_retention_days');
        $retentionDays = is_int($retentionDays) && $retentionDays > 0 ? $retentionDays : null;

        $now = Carbon::now('UTC');
        $staleThreshold = $now->copy()->subSeconds($reservationTimeoutSeconds * 2);

        $counts = $this->collectCounts($connection, $outboxTable, $staleThreshold);
        $ageDistribution = [
            'pending' => $this->ageBuckets($connection, $outboxTable, 'pending', $now),
            'dead_letter' => $this->ageBuckets($connection, $outboxTable, 'dead_letter', $now),
        ];
        $topDeadLetterCategories = $this->topDeadLetterCategories($connection, $outboxTable);
        $oldest = [
            'pending' => $this->oldestRow($connection, $outboxTable, 'pending', $now),
            'dead_letter' => $this->oldestRow($connection, $outboxTable, 'dead_letter', $now),
        ];
        $retention = [
            'dead_letter_retention_days' => $retentionDays,
            'next_prune_count' => $this->nextPruneCount($connection, $outboxTable, $retentionDays, $now),
        ];

        $summary = [
            'ok' => true,
            'store' => 'database',
            'available' => true,
            'counts' => $counts,
            'age_distribution' => $ageDistribution,
            'top_dead_letter_categories' => $topDeadLetterCategories,
            'oldest' => $oldest,
            'retention' => $retention,
        ];

        if ($this->option('json') === true) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHuman($summary, $reservationTimeoutSeconds);

        return self::SUCCESS;
    }

    protected function renderUnavailable(): int
    {
        if ($this->option('json') === true) {
            $this->line((string) json_encode([
                'ok' => true,
                'store' => 'cache',
                'available' => false,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info('Audit outbox is not available on the current persistence driver — switch swarm.persistence.driver to "database" to enable it.');

        return self::SUCCESS;
    }

    /**
     * @return array{pending: int, reserved: int, stale_reserved: int, dead_letter: int}
     */
    protected function collectCounts(Connection $connection, string $outboxTable, Carbon $staleThreshold): array
    {
        $pending = $this->outboxQuery($connection, $outboxTable)
            ->where('status', 'pending')
            ->whereNull('reserved_at')
            ->count();

        $reserved = $this->outboxQuery($connection, $outboxTable)
            ->where('status', 'pending')
            ->whereNotNull('reserved_at')
            ->count();

        $staleReserved = $this->outboxQuery($connection, $outboxTable)
            ->where('status', 'pending')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $staleThreshold)
            ->count();

        $deadLetter = $this->outboxQuery($connection, $outboxTable)
            ->where('status', 'dead_letter')
            ->count();

        return [
            'pending' => $pending,
            'reserved' => $reserved,
            'stale_reserved' => $staleReserved,
            'dead_letter' => $deadLetter,
        ];
    }

    /**
     * @return array{lt_1h: int, h1_24: int, d1_7: int, gt_7d: int}
     */
    protected function ageBuckets(Connection $connection, string $outboxTable, string $status, Carbon $now): array
    {
        $oneHour = $now->copy()->subHour();
        $oneDay = $now->copy()->subDay();
        $sevenDays = $now->copy()->subDays(7);

        $base = fn (): Builder => $this->outboxQuery($connection, $outboxTable)->where('status', $status);

        return [
            'lt_1h' => (clone $base())->where('created_at', '>=', $oneHour)->count(),
            'h1_24' => (clone $base())->where('created_at', '<', $oneHour)->where('created_at', '>=', $oneDay)->count(),
            'd1_7' => (clone $base())->where('created_at', '<', $oneDay)->where('created_at', '>=', $sevenDays)->count(),
            'gt_7d' => (clone $base())->where('created_at', '<', $sevenDays)->count(),
        ];
    }

    /**
     * @return array<int, array{category: string, count: int}>
     */
    protected function topDeadLetterCategories(Connection $connection, string $outboxTable): array
    {
        return $this->outboxQuery($connection, $outboxTable)
            ->where('status', 'dead_letter')
            ->groupBy('category')
            ->selectRaw('category, COUNT(*) as aggregate')
            ->orderByDesc('aggregate')
            ->orderBy('category')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => [
                'category' => (string) $row->category,
                'count' => (int) $row->aggregate,
            ])
            ->all();
    }

    /**
     * @return array{id: int, age: string}|null
     */
    protected function oldestRow(Connection $connection, string $outboxTable, string $status, Carbon $now): ?array
    {
        $row = $this->outboxQuery($connection, $outboxTable)
            ->where('status', $status)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['id', 'created_at']);

        if ($row === null) {
            return null;
        }

        $createdAt = Carbon::parse($row->created_at, 'UTC');

        return [
            'id' => (int) $row->id,
            'age' => $createdAt->diffForHumans($now, [
                'parts' => 2,
                'short' => true,
                'syntax' => Carbon::DIFF_ABSOLUTE,
            ]),
        ];
    }

    protected function nextPruneCount(Connection $connection, string $outboxTable, ?int $retentionDays, Carbon $now): int
    {
        if ($retentionDays === null) {
            return 0;
        }

        return $this->outboxQuery($connection, $outboxTable)
            ->where('status', 'dead_letter')
            ->where('last_attempted_at', '<', $now->copy()->subDays($retentionDays))
            ->count();
    }

    /**
     * @param  array{ok: bool, store: string, available: bool, counts: array<string, int>, age_distribution: array<string, array<string, int>>, top_dead_letter_categories: array<int, array{category: string, count: int}>, oldest: array<string, array{id: int, age: string}|null>, retention: array{dead_letter_retention_days: int|null, next_prune_count: int}}  $summary
     */
    protected function renderHuman(array $summary, int $reservationTimeoutSeconds): void
    {
        $counts = $summary['counts'];
        $staleWindow = $reservationTimeoutSeconds * 2;

        $this->components->info('Audit outbox summary');

        $this->table(
            ['Status', 'Count'],
            [
                ['pending (unclaimed)', (string) $counts['pending']],
                ['reserved', (string) $counts['reserved']],
                ["  stale (> {$staleWindow}s)", (string) $counts['stale_reserved']],
                ['dead_letter', (string) $counts['dead_letter']],
            ],
        );

        $this->line('');
        $this->components->info('Age distribution');
        $this->table(
            ['Status', '< 1h', '1-24h', '1-7d', '> 7d'],
            [
                [
                    'pending',
                    (string) $summary['age_distribution']['pending']['lt_1h'],
                    (string) $summary['age_distribution']['pending']['h1_24'],
                    (string) $summary['age_distribution']['pending']['d1_7'],
                    (string) $summary['age_distribution']['pending']['gt_7d'],
                ],
                [
                    'dead_letter',
                    (string) $summary['age_distribution']['dead_letter']['lt_1h'],
                    (string) $summary['age_distribution']['dead_letter']['h1_24'],
                    (string) $summary['age_distribution']['dead_letter']['d1_7'],
                    (string) $summary['age_distribution']['dead_letter']['gt_7d'],
                ],
            ],
        );

        $this->line('');
        $this->components->info('Top dead-letter categories');

        if ($summary['top_dead_letter_categories'] === []) {
            $this->components->bulletList(['no dead-letter rows']);
        } else {
            $this->table(
                ['Category', 'Count'],
                array_map(
                    fn (array $row): array => [$row['category'], (string) $row['count']],
                    $summary['top_dead_letter_categories'],
                ),
            );
        }

        $this->line('');
        $this->components->info('Oldest rows');
        $this->table(
            ['Status', 'ID', 'Age'],
            [
                [
                    'pending',
                    $summary['oldest']['pending']['id'] ?? 'n/a',
                    $summary['oldest']['pending']['age'] ?? 'n/a',
                ],
                [
                    'dead_letter',
                    $summary['oldest']['dead_letter']['id'] ?? 'n/a',
                    $summary['oldest']['dead_letter']['age'] ?? 'n/a',
                ],
            ],
        );

        $this->line('');
        $this->components->info('Retention');
        $retentionDays = $summary['retention']['dead_letter_retention_days'];
        $this->components->bulletList([
            $retentionDays === null
                ? 'dead_letter_retention_days: not configured (rows retained indefinitely)'
                : "dead_letter_retention_days: {$retentionDays}",
            "next-prune count: {$summary['retention']['next_prune_count']}",
        ]);
    }

    protected function outboxQuery(Connection $connection, string $outboxTable): Builder
    {
        return $connection->table($outboxTable);
    }
}
