<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Console\Commands;

use {{ rootNamespace }}\Ai\Swarms\NativeMediaRetrieval\MediaRetrievalPipeline;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runner for the native-media-retrieval starter example.
 *
 * Run it: `php artisan swarm:example:media-retrieval "your question here"`
 */
#[AsCommand(name: 'swarm:example:media-retrieval')]
class SwarmExampleMediaRetrievalCommand extends Command
{
    protected $signature = 'swarm:example:media-retrieval {question? : A question to answer from the knowledge base}';

    protected $description = 'Run the native-media-retrieval starter example end-to-end.';

    public function handle(): int
    {
        $question = $this->argument('question')
            ?? 'What execution modes do Swarm workflows run in?';

        $this->components->info('Answering from the knowledge base with native retrieval');

        $response = MediaRetrievalPipeline::make()->prompt((string) $question);

        $this->components->twoColumnDetail('Run ID', $response->context?->runId ?? '(no context)');
        $this->components->twoColumnDetail('Steps', (string) count($response->steps));

        $this->newLine();
        $this->line('--- Grounded answer ---');
        $this->line($response->output);

        return self::SUCCESS;
    }
}
