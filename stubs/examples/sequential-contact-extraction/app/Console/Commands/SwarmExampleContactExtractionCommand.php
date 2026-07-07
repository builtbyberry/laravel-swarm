<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Console\Commands;

use {{ rootNamespace }}\Ai\Swarms\SequentialContactExtraction\ContactExtractor;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runner for the sequential-contact-extraction starter example.
 *
 * Run it: `php artisan swarm:example:contact-extraction "your blurb here"`
 */
#[AsCommand(name: 'swarm:example:contact-extraction')]
class SwarmExampleContactExtractionCommand extends Command
{
    protected $signature = 'swarm:example:contact-extraction {blurb? : Unstructured text to extract a contact from}';

    protected $description = 'Run the sequential-contact-extraction starter example end-to-end.';

    public function handle(): int
    {
        $blurb = $this->argument('blurb')
            ?? 'Hi, this is Ada Lovelace — reach me at ada@analytical-engines.example or 555 0100.';

        $this->components->info('Extracting a structured contact record');

        $response = ContactExtractor::make()->prompt((string) $blurb);

        $record = json_decode($response->output, true);

        $this->components->twoColumnDetail('Run ID', $response->context?->runId ?? '(no context)');
        $this->components->twoColumnDetail('Steps', (string) count($response->steps));
        $this->components->twoColumnDetail('Valid record', ($record['valid'] ?? false) ? 'yes' : 'no');

        $this->newLine();
        $this->line('--- Validated structured record ---');
        $this->line($response->output);

        return self::SUCCESS;
    }
}
