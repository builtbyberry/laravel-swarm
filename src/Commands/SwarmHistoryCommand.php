<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Support\SwarmRunPhase;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:history')]
class SwarmHistoryCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:history {--swarm=} {--status=} {--limit=25}';

    protected $description = 'List persisted swarm runs with optional filters';

    public function handle(SwarmHistory $history): int
    {
        $limit = $this->optionInt('limit', 25);
        $swarm = $this->option('swarm');
        $status = $this->option('status');
        $swarmStr = is_string($swarm) ? $swarm : null;
        $statusStr = is_string($status) ? $status : null;

        if ($swarmStr !== null || $statusStr !== null) {
            $query = $swarmStr !== null
                ? $history->forSwarm($swarmStr)
                : $history->withStatus($statusStr ?? '');

            if ($statusStr !== null) {
                $query = $query->withStatus($statusStr);
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
            ['Run ID', 'Swarm', 'Topology', 'Status', 'Phase', 'Steps', 'Started'],
            array_map(fn (array $run): array => [
                $run['run_id'],
                class_basename($run['swarm_class']),
                $run['topology'],
                $run['status'],
                SwarmRunPhase::cliLabel($run),
                count($run['steps'] ?? []),
                isset($run['started_at']) ? Carbon::parse($run['started_at'], 'UTC')->setTimezone(config('app.timezone'))->toDateTimeString() : 'n/a',
            ], $runs),
        );

        return self::SUCCESS;
    }
}
