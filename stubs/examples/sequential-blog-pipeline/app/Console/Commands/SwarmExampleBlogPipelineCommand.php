<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Console\Commands;

use {{ rootNamespace }}\Ai\Swarms\SequentialBlogPipeline\BlogPipeline;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runner for the sequential-blog-pipeline starter example.
 *
 * Run it: `php artisan swarm:example:blog-pipeline "your topic here"`
 */
#[AsCommand(name: 'swarm:example:blog-pipeline')]
class SwarmExampleBlogPipelineCommand extends Command
{
    protected $signature = 'swarm:example:blog-pipeline {topic? : Topic to draft a post about}';

    protected $description = 'Run the sequential-blog-pipeline starter example end-to-end.';

    public function handle(): int
    {
        $topic = $this->argument('topic') ?? 'Laravel queue visibility timeouts';

        $this->components->info("Running BlogPipeline on topic: {$topic}");

        $response = BlogPipeline::make()->prompt((string) $topic);

        $this->components->twoColumnDetail('Run ID', $response->context?->runId ?? '(no context)');
        $this->components->twoColumnDetail('Steps', (string) count($response->steps));

        $this->newLine();
        $this->line('--- Final output ---');
        $this->line($response->output);

        return self::SUCCESS;
    }
}
