<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Upgrade\UpgradeConsole;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'swarm:upgrade')]
class SwarmUpgradeCommand extends Command
{
    protected $signature = 'swarm:upgrade
        {--path= : Application directory (defaults to this application)}
        {--recipe=0.25-to-0.26 : Upgrade recipe (0.25-to-0.26 or 0.26-to-0.27)}
        {--json : Print a machine-readable upgrade report}
        {--apply= : Comma-separated action IDs from a reviewed preview}
        {--expect= : Require this exact preview SHA-256 before applying}
        {--yes : Explicitly approve selected apply or restore operation}
        {--restore= : Restore a manifest backup only if later edits will not be lost}';

    protected $description = 'Preview a selected Swarm upgrade recipe and explicitly apply safe dependency fixes';

    protected $help = UpgradeConsole::HELP;

    public function handle(UpgradeConsole $console): int
    {
        return $console->run($this->options(), base_path(), function (string $line): void {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        });
    }
}
