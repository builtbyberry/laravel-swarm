<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Install;

use BuiltByBerry\LaravelSwarm\Memory\DefaultPropagationPolicy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * `swarm:install:memory` — targeted setup for the Swarm Memory subsystem.
 *
 * Standalone sub-installer that prepares a host Laravel application for the
 * v0.9.0 memory subsystem: verifies the memory tables are present (and offers
 * to run the migrations if not), then prints the current memory configuration
 * alongside verification and doc links.
 *
 * What this command owns directly:
 *
 *   - Check that `swarm_memories` and `swarm_memory_snapshots` exist, or
 *     offer to run `php artisan migrate` to create them.
 *   - Print the effective driver (`swarm.persistence.driver` /
 *     `swarm.memory.driver`) and the active `swarm.memory.replay_mode`.
 *   - Cross-link to `swarm:health`, `docs/memory.md`, and the
 *     `#[MemoryReplay]` attribute for operators who want to tune replay.
 *
 * The memory store binding is wired automatically by `SwarmServiceProvider`
 * based on the persistence driver configuration — no service provider mutation
 * is needed. The main `swarm:install` command seeds `SWARM_MEMORY_REPLAY_MODE`
 * into `.env` as part of its global env-seeding pass.
 */
#[AsCommand(name: 'swarm:install:memory')]
class InstallMemoryCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'swarm:install:memory
                            {--migrate : Run any pending memory migrations without prompting}
                            {--skip-migrate : Skip running migrations even if they are pending}';

    /**
     * @var string
     */
    protected $description = 'Set up the Swarm Memory subsystem (memory tables, replay config).';

    public function handle(ConfigRepository $config, Connection $connection): int
    {
        $this->components->info('Installing the Swarm Memory subsystem.');
        $this->newLine();

        // 1. Resolve and print the effective memory driver.
        $driver = $this->resolveMemoryDriver($config);
        $this->components->twoColumnDetail('Memory driver', $driver);

        if ($driver !== 'database') {
            $this->newLine();
            $this->components->warn(
                "Memory driver is [{$driver}]. "
                .'The memory tables (`swarm_memories`, `swarm_memory_snapshots`) require the database driver — '
                .'entries will not be durable until you switch. '
                .'Set SWARM_PERSISTENCE_DRIVER=database (or swarm.memory.driver=database) to provision durable memory.',
            );
            $this->printCurrentConfig($config);
            $this->printNextSteps();

            return self::SUCCESS;
        }

        // 2. Check / run memory migrations.
        $missingTables = $this->detectMissingMemoryTables($connection, $config);

        if ($missingTables !== []) {
            $this->components->warn(
                'Memory tables are missing: '.implode(', ', $missingTables),
            );

            if ($this->shouldRunMigrations()) {
                try {
                    $this->call('migrate', ['--force' => true]);
                } catch (Throwable $e) {
                    $this->components->error('Migrations failed: '.$e->getMessage());

                    return self::FAILURE;
                }

                $stillMissing = $this->detectMissingMemoryTables($connection, $config);

                if ($stillMissing !== []) {
                    $this->components->error(
                        'Migrations ran but memory tables are still missing: '.implode(', ', $stillMissing),
                    );
                    $this->line(
                        'Verify the package migrations are published '
                        .'(`php artisan vendor:publish --tag=swarm-migrations`) '
                        .'or that your database connection matches your application config.',
                    );

                    return self::FAILURE;
                }

                $this->components->info('Memory migrations: applied');
            } else {
                $this->components->warn(
                    'Skipping migrations. Run `php artisan migrate` before using Swarm Memory.',
                );
            }
        } else {
            $this->components->info('Memory migrations: present');
        }

        // 3. Print current config and next steps.
        $this->printCurrentConfig($config);
        $this->printNextSteps();

        return self::SUCCESS;
    }

    /**
     * Resolve the effective memory driver.
     *
     * Checks `swarm.memory.driver` first (per-subsystem override), then falls
     * back to the global `swarm.persistence.driver`. Mirrors the resolution
     * logic in `SwarmServiceProvider::resolvePersistenceStore()`.
     */
    private function resolveMemoryDriver(ConfigRepository $config): string
    {
        $driver = $config->get('swarm.memory.driver');

        if (is_string($driver) && $driver !== '') {
            return $driver;
        }

        return (string) $config->get('swarm.persistence.driver', 'cache');
    }

    /**
     * Decide whether to run pending migrations, respecting `--migrate`,
     * `--skip-migrate`, and `--no-interaction`.
     *
     * Non-interactive default: skip and warn. Operators can re-run with
     * `--migrate` to opt in explicitly.
     */
    private function shouldRunMigrations(): bool
    {
        if ((bool) $this->option('skip-migrate') === true) {
            return false;
        }

        if ((bool) $this->option('migrate') === true) {
            return true;
        }

        if ($this->input->isInteractive()) {
            return confirm(label: 'Run pending memory migrations now?', default: true);
        }

        return false;
    }

    /**
     * Check that `swarm_memories` and `swarm_memory_snapshots` are present.
     * Returns the names of any missing tables.
     *
     * A schema-check failure (database unreachable, permissions error) is
     * treated as missing rather than silently passing — the operator needs a
     * real error rather than a clean bill of health that does not reflect
     * reality.
     *
     * @return array<int, string>
     */
    private function detectMissingMemoryTables(Connection $connection, ConfigRepository $config): array
    {
        $memoriesTable = (string) $config->get('swarm.tables.memories', 'swarm_memories');
        $snapshotsTable = (string) $config->get('swarm.tables.memory_snapshots', 'swarm_memory_snapshots');

        $schema = $connection->getSchemaBuilder();
        $missing = [];

        foreach ([$memoriesTable, $snapshotsTable] as $table) {
            try {
                if (! $schema->hasTable($table)) {
                    $missing[] = $table;
                }
            } catch (Throwable) {
                // Cannot confirm the table exists — surface as missing.
                $missing[] = $table;
            }
        }

        return $missing;
    }

    private function printCurrentConfig(ConfigRepository $config): void
    {
        $replayMode = (string) $config->get('swarm.memory.replay_mode', 'frozen_view');
        $propagationPolicy = (string) $config->get('swarm.memory.propagation_policy', DefaultPropagationPolicy::class);

        $this->newLine();
        $this->components->info('Current memory configuration');
        $this->components->twoColumnDetail(
            'SWARM_MEMORY_REPLAY_MODE',
            $replayMode.' '.$this->explainReplayMode($replayMode),
        );
        $this->components->twoColumnDetail(
            'Propagation policy',
            class_basename($propagationPolicy).' '.$this->explainPropagationPolicy($propagationPolicy),
        );
    }

    private function explainPropagationPolicy(string $policy): string
    {
        return $policy === DefaultPropagationPolicy::class
            ? '(Run-scope view only — preserves pre-v0.10 behaviour)'
            : '(custom — overrides what workers see; override per swarm with #[PropagationPolicy])';
    }

    private function explainReplayMode(string $mode): string
    {
        return match ($mode) {
            'frozen_view' => '(deterministic replay from frozen snapshot — recommended)',
            'fresh_execution' => '(live memory on retry — use only when idempotency is guaranteed externally)',
            default => '',
        };
    }

    private function printNextSteps(): void
    {
        $this->newLine();
        $this->components->info('Next steps');
        $this->line('  • Verify the install: <comment>php artisan swarm:health</comment>');
        $this->line('  • Full memory reference: <comment>docs/memory.md</comment>');
        $this->line('  • Per-swarm replay tuning: <comment>#[MemoryReplay(mode: ReplayMode::FreshExecution)]</comment>');
        $this->line('  • Per-swarm worker visibility: <comment>#[PropagationPolicy(MyPolicy::class)]</comment>');

        $this->printOctaneNote();
    }

    /**
     * When running under Laravel Octane, Swarm flushes the process-local active
     * run context on every worker reset automatically (a wired
     * `OperationTerminated` listener). Surface this only when Octane is present
     * so the note stays out of the way for everyone else, and document the
     * manual `flush()` equivalent for operators who want it in `octane.php`.
     */
    private function printOctaneNote(): void
    {
        if (! interface_exists('Laravel\Octane\Contracts\OperationTerminated')) {
            return;
        }

        $this->newLine();
        $this->components->info('Laravel Octane detected');
        $this->line(
            '  Swarm flushes its process-local active run context on every Octane '
            .'worker reset automatically — no configuration needed.',
        );
        $this->line(
            '  To flush it yourself instead, add to <comment>config/octane.php</comment> under each '
            .'<comment>*Terminated</comment> listener:',
        );
        $this->line('    <comment>\BuiltByBerry\LaravelSwarm\Support\ActiveRunContext::flush(...),</comment>');
    }
}
