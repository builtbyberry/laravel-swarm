<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:inspect')]
class SwarmInspectCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:inspect {runId : The durable run ID} {--json : Output JSON}';

    protected $description = 'Inspect durable swarm run operational state';

    public function handle(DurableSwarmManager $manager): int
    {
        $detail = $manager->inspect($this->argumentString('runId'))->toArray();

        if ($this->option('json')) {
            $encoded = json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->line($encoded !== false ? $encoded : '{}');

            return self::SUCCESS;
        }

        $run = $detail['run'] ?? [];
        $this->table(['Field', 'Value'], [
            ['Run ID', $detail['run_id']],
            ['Swarm', class_basename((string) ($run['swarm_class'] ?? 'n/a'))],
            ['Status', (string) ($run['status'] ?? 'n/a')],
            ['Topology', (string) ($run['topology'] ?? 'n/a')],
            ['Labels', json_encode($detail['labels'])],
            ['Details', json_encode($detail['details'])],
            ['Waits', (string) count($detail['waits'])],
            ['Signals', (string) count($detail['signals'])],
            ['Progress Records', (string) count($detail['progress'])],
            ['Child Swarms', (string) count($detail['children'])],
        ]);

        return self::SUCCESS;
    }
}
