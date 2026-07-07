<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Console\Commands;

use {{ rootNamespace }}\Ai\Swarms\SequentialConversationMemory\ConversationMemory;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runner for the sequential-conversation-memory starter example.
 *
 * Run it: `php artisan swarm:example:conversation-memory "your message here"`
 */
#[AsCommand(name: 'swarm:example:conversation-memory')]
class SwarmExampleConversationMemoryCommand extends Command
{
    protected $signature = 'swarm:example:conversation-memory {message? : A customer message to remember a detail from and reply to}';

    protected $description = 'Run the sequential-conversation-memory starter example end-to-end.';

    public function handle(): int
    {
        $message = $this->argument('message')
            ?? 'Following up on ticket HD-2291 — the export still times out on large reports.';

        $this->components->info('Running a two-step swarm that shares a fact through memory');

        $response = ConversationMemory::make()->prompt((string) $message);

        $this->components->twoColumnDetail('Run ID', $response->context?->runId ?? '(no context)');
        $this->components->twoColumnDetail('Steps', (string) count($response->steps));
        $this->components->twoColumnDetail('Step 1 output (subject omitted)', $response->steps[0]->output ?? '');

        $this->newLine();
        $this->line('--- Reply composed from recalled memory ---');
        $this->line((string) $response);

        return self::SUCCESS;
    }
}
