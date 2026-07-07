<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Console\Commands;

use {{ rootNamespace }}\Ai\Swarms\HierarchicalSupportTriage\SupportTriageRouter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runner for the hierarchical-support-triage starter example.
 *
 * Run it: `php artisan swarm:example:support-triage "I was double charged"`
 */
#[AsCommand(name: 'swarm:example:support-triage')]
class SwarmExampleSupportTriageCommand extends Command
{
    protected $signature = 'swarm:example:support-triage {request? : The incoming support request}';

    protected $description = 'Run the hierarchical-support-triage starter example end-to-end.';

    public function handle(): int
    {
        $request = (string) ($this->argument('request') ?? 'I was double charged on my last invoice.');

        $this->components->info("Triaging request: {$request}");

        $response = SupportTriageRouter::make()->prompt($request);

        // The coordinator runs first (step 0); the routed handler runs next.
        $handler = $response->steps[1]->agentClass ?? '(no handler ran)';

        $this->components->twoColumnDetail('Run ID', $response->context?->runId ?? '(no context)');
        $this->components->twoColumnDetail('Routed to', class_basename($handler));

        $this->newLine();
        $this->line($response->output);

        return self::SUCCESS;
    }
}
