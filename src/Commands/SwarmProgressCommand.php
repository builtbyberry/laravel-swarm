<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:progress')]
class SwarmProgressCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:progress {runId : The durable run ID}';

    protected $description = 'Show durable swarm progress records';

    public function handle(DurableSwarmManager $manager): int
    {
        $detail = $manager->inspect($this->argumentString('runId'));

        if ($detail->progress === []) {
            $this->components->info('No progress has been recorded for this durable run.');

            return self::SUCCESS;
        }

        $this->table(
            ['Run ID', 'Branch', 'Step', 'Agent', 'Last Progress', 'Progress'],
            array_map(static fn (array $progress): array => [
                $progress['run_id'],
                $progress['branch_id'] ?? 'parent',
                $progress['step_index'] ?? 'n/a',
                isset($progress['agent_class']) ? class_basename((string) $progress['agent_class']) : 'n/a',
                $progress['last_progress_at'],
                json_encode($progress['progress']),
            ], $detail->progress),
        );

        return self::SUCCESS;
    }
}
