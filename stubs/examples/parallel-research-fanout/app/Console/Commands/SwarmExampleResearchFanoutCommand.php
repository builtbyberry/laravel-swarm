<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Console\Commands;

use {{ rootNamespace }}\Ai\Swarms\ParallelResearchFanout\ResearchFanout;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runner for the parallel-research-fanout starter example.
 *
 * Run it: `php artisan swarm:example:research-fanout "your topic"`
 */
#[AsCommand(name: 'swarm:example:research-fanout')]
class SwarmExampleResearchFanoutCommand extends Command
{
    protected $signature = 'swarm:example:research-fanout {topic? : Research topic}';

    protected $description = 'Run the parallel-research-fanout starter example end-to-end.';

    public function handle(): int
    {
        $topic = $this->argument('topic') ?? 'AI agent orchestration for Laravel apps';

        $this->components->info("Running ResearchFanout on topic: {$topic}");

        $response = ResearchFanout::make()->prompt((string) $topic);

        $this->components->twoColumnDetail('Run ID', $response->context?->runId ?? '(no context)');
        $this->components->twoColumnDetail('Agents run in parallel', (string) count($response->steps));

        foreach ($response->steps as $step) {
            $this->newLine();
            $this->line('--- '.$step->agentClass.' ---');
            $this->line($step->output);
        }

        return self::SUCCESS;
    }
}
