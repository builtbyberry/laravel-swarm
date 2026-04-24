<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:history')]
class SwarmHistoryCommand extends Command
{
    protected $signature = 'swarm:history {--swarm=} {--status=} {--limit=25}';

    protected $description = 'List persisted swarm runs with optional filters';

    public function handle(SwarmHistory $history): int
    {
        $limit = (int) $this->option('limit');
        $swarm = $this->option('swarm');
        $status = $this->option('status');

        if ($swarm !== null || $status !== null) {
            $query = $swarm !== null
                ? $history->forSwarm((string) $swarm)
                : $history->withStatus((string) $status);

            if ($status !== null) {
                $query = $query->withStatus((string) $status);
            }

            $runs = $query->limit($limit)->get();
        } else {
            $runs = $history->latest($limit);
        }

        if ($runs === []) {
            $this->components->info('No matching swarm runs found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Run ID', 'Swarm', 'Topology', 'Status', 'Steps', 'Started'],
            array_map(fn (array $run): array => [
                $run['run_id'],
                class_basename($run['swarm_class']),
                $run['topology'],
                $run['status'],
                count($run['steps'] ?? []),
                isset($run['started_at']) ? Carbon::parse($run['started_at'], 'UTC')->setTimezone(config('app.timezone'))->toDateTimeString() : 'n/a',
            ], $runs),
        );

        return self::SUCCESS;
    }
}
