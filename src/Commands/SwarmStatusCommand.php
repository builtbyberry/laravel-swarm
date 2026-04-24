<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:status')]
class SwarmStatusCommand extends Command
{
    protected $signature = 'swarm:status {--run-id=}';

    protected $description = 'Inspect the status of a swarm run or the most recent swarm runs';

    public function handle(SwarmHistory $history): int
    {
        $runId = $this->option('run-id');
        $runs = $runId ? array_filter([$history->find((string) $runId)]) : $history->latest(10);

        if ($runs === []) {
            $this->components->info('No swarm runs found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Run ID', 'Swarm', 'Topology', 'Status', 'Steps', 'Duration'],
            array_map(fn (array $run): array => [
                $run['run_id'],
                class_basename($run['swarm_class']),
                $run['topology'],
                $run['status'],
                count($run['steps'] ?? []),
                $this->formatDuration($run),
            ], $runs),
        );

        return self::SUCCESS;
    }

    protected function formatDuration(array $run): string
    {
        $startedAt = isset($run['started_at']) ? Carbon::parse($run['started_at'], 'UTC') : null;

        if ($startedAt === null) {
            return 'n/a';
        }

        $finishedAt = isset($run['finished_at']) && $run['finished_at'] !== null
            ? Carbon::parse($run['finished_at'], 'UTC')
            : Carbon::now('UTC');

        return $startedAt->diffForHumans($finishedAt, [
            'parts' => 2,
            'short' => true,
            'syntax' => Carbon::DIFF_ABSOLUTE,
        ]);
    }
}
