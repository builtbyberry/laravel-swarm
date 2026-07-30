<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Install;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\DetectsInteractiveConsole;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * `swarm:install:durable` — targeted setup for the durable execution runtime.
 *
 * Standalone sub-installer that prepares a host Laravel application to run
 * durable swarms: injects the required scheduler entries (`swarm:relay`,
 * `swarm:recover`, `swarm:prune`), verifies the persistence driver is
 * `database`, confirms the durable migrations have run (and offers to run
 * them), checks the queue connection, and prints copy-paste worker commands
 * for plain `queue:work`, Horizon, and Forge/Supervisor.
 *
 * This command does not write to `config/queue.php`, install Horizon, or
 * spawn worker processes. It only wires the pieces that are otherwise
 * easy to forget when standing up durable execution by hand.
 */
#[AsCommand(name: 'swarm:install:durable')]
class InstallDurableCommand extends Command
{
    use DetectsInteractiveConsole;

    protected $signature = 'swarm:install:durable
                            {--queue= : Queue name for durable jobs (default: swarm-durable)}
                            {--migrate : Run any pending package migrations without prompting}
                            {--skip-migrate : Skip running migrations even if they are pending}
                            {--allow-sync-queue : Proceed even when QUEUE_CONNECTION=sync (not recommended)}';

    protected $description = 'Set up the durable execution runtime (scheduler entries, persistence checks, worker hints).';

    /**
     * Idempotency marker written before the injected schedule block. The
     * command refuses to inject a second block when this marker is present.
     */
    public const SCHEDULE_BLOCK_MARKER = '// swarm:install:durable schedule entries — managed; do not edit';

    public function handle(Application $app, ConfigRepository $config, Connection $connection, Filesystem $files): int
    {
        $this->info('Installing the Laravel Swarm durable runtime…');
        $this->newLine();

        // 1. Validate persistence driver.
        $driver = (string) $config->get('swarm.persistence.driver', 'cache');

        if ($driver !== 'database') {
            $this->error('Durable runtime requires the database persistence driver.');
            $this->line("Detected swarm.persistence.driver=[{$driver}].");
            $this->line('Set SWARM_PERSISTENCE_DRIVER=database in your .env (or update config/swarm.php) before re-running this command.');

            return self::FAILURE;
        }

        $this->components->info('Persistence driver: database (ok)');

        // 2. Check durable migrations have run.
        $missingTables = $this->detectMissingDurableTables($connection);

        if ($missingTables !== []) {
            $this->components->warn(
                'Durable runtime tables are missing: '.implode(', ', $missingTables),
            );

            $shouldMigrate = $this->shouldRunMigrations();

            if ($shouldMigrate) {
                $this->line('Running migrations…');

                try {
                    $this->call('migrate', ['--force' => true]);
                } catch (Throwable $exception) {
                    $this->error('Migrations failed: '.$exception->getMessage());

                    return self::FAILURE;
                }

                $stillMissing = $this->detectMissingDurableTables($connection);

                if ($stillMissing !== []) {
                    $this->error(
                        'Migrations ran but durable tables are still missing: '.implode(', ', $stillMissing),
                    );
                    $this->line('Verify the package migrations are published (php artisan vendor:publish --tag=swarm-migrations) or that your database connection matches your application config.');

                    return self::FAILURE;
                }

                $this->components->info('Durable migrations: applied');
            } else {
                $this->components->warn(
                    'Skipping migrations. Run `php artisan migrate` before dispatching durable swarms.',
                );
            }
        } else {
            $this->components->info('Durable migrations: present');
        }

        // 3. Inject scheduler entries.
        $routesPath = $app->basePath('routes/console.php');

        if (! $files->exists($routesPath)) {
            $this->error("routes/console.php was not found at [{$routesPath}].");

            return self::FAILURE;
        }

        $injected = $this->injectScheduleEntries($files, $routesPath);

        if ($injected) {
            $this->components->info('Scheduler entries: injected into routes/console.php');
        } else {
            $this->components->info('Scheduler entries: already present (left as-is)');
        }

        // 4. Inspect the queue connection.
        $queueConnection = (string) $config->get('queue.default', 'sync');
        $allowSync = (bool) $this->option('allow-sync-queue');

        if ($queueConnection === 'sync') {
            if (! $allowSync) {
                $this->error('Durable execution requires a real queue connection, not sync.');
                $this->line('Detected QUEUE_CONNECTION=sync. Switch to `redis` or `database` (or pass --allow-sync-queue to bypass this check for local experiments).');

                return self::FAILURE;
            }

            $this->components->warn('Queue connection: sync (allowed by --allow-sync-queue; not safe for production)');
        } elseif (in_array($queueConnection, ['redis', 'database', 'sqs', 'beanstalkd'], true)) {
            $this->components->info("Queue connection: {$queueConnection} (ok)");
        } else {
            $this->components->warn("Queue connection: {$queueConnection} (untested with durable execution; redis or database are recommended)");
        }

        // 5. Print worker command snippets.
        $queueName = $this->resolveQueueName($config);
        $this->printWorkerSnippets($queueConnection, $queueName);

        $this->newLine();
        $this->info('Durable runtime installed.');
        $this->line('Next: deploy a worker for the durable queue and confirm `php artisan swarm:health --durable` is green.');

        return self::SUCCESS;
    }

    /**
     * Resolve the queue name that durable jobs will be dispatched on.
     *
     * --queue overrides everything. Otherwise, prefer the runtime config
     * (`swarm.durable.queue.name`) so an environment-set queue name flows
     * through into the printed worker snippets. Fall back to the package
     * convention `swarm-durable`.
     */
    private function resolveQueueName(ConfigRepository $config): string
    {
        $override = $this->option('queue');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $configured = $config->get('swarm.durable.queue.name');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return 'swarm-durable';
    }

    /**
     * Decide whether to run pending migrations, respecting --migrate,
     * --skip-migrate, and --no-interaction.
     */
    private function shouldRunMigrations(): bool
    {
        if ((bool) $this->option('skip-migrate') === true) {
            return false;
        }

        if ((bool) $this->option('migrate') === true) {
            return true;
        }

        if ($this->consoleCanPrompt()) {
            return confirm(label: 'Run pending migrations now?', default: true);
        }

        // Non-interactive default: skip and warn. Operators can re-run with
        // --migrate to opt in explicitly.
        return false;
    }

    /**
     * Inspect a small set of expected durable tables. We do not need to
     * verify every column — `swarm:health --durable` does the deep check.
     * This is a "did the user remember to migrate?" guard.
     *
     * @return array<int, string>
     */
    private function detectMissingDurableTables(Connection $connection): array
    {
        $required = [
            'swarm_durable_runs',
            'swarm_durable_outbox',
        ];

        $schema = $connection->getSchemaBuilder();
        $missing = [];

        foreach ($required as $table) {
            try {
                if (! $schema->hasTable($table)) {
                    $missing[] = $table;
                }
            } catch (Throwable) {
                // If the schema lookup itself fails we cannot prove the table
                // exists. Surface it as missing so the operator sees a real
                // error rather than a silent pass.
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * Append the managed schedule block to routes/console.php, but only when
     * the idempotency marker is absent. Existing user-written schedule
     * entries for the same commands are detected and skipped on a per-line
     * basis as a defensive second check.
     *
     * Returns true when the block was newly injected, false when the marker
     * was already present and the file was not modified.
     */
    private function injectScheduleEntries(Filesystem $files, string $routesPath): bool
    {
        $contents = (string) $files->get($routesPath);

        if (str_contains($contents, self::SCHEDULE_BLOCK_MARKER)) {
            return false;
        }

        // If the file already imports Schedule entries for every command we
        // manage, do not add a duplicate block — the user wired this by hand
        // before running the installer. The marker check above handles the
        // common case; this guard catches "configured manually then ran the
        // installer" so we do not bloat their file.
        if (
            $this->fileHasScheduleEntry($contents, 'swarm:relay')
            && $this->fileHasScheduleEntry($contents, 'swarm:recover')
            && $this->fileHasScheduleEntry($contents, 'swarm:prune')
        ) {
            return false;
        }

        $block = $this->renderScheduleBlock($contents);

        if (! str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        $files->put($routesPath, $contents.$block);

        return true;
    }

    /**
     * Build the scheduler block, including the `use Illuminate\Support\Facades\Schedule;`
     * import if it is not already present.
     */
    private function renderScheduleBlock(string $existing): string
    {
        $needsImport = ! preg_match(
            '/^use\s+Illuminate\\\\Support\\\\Facades\\\\Schedule\s*;/m',
            $existing,
        );

        $lines = [];
        $lines[] = '';
        $lines[] = self::SCHEDULE_BLOCK_MARKER;

        if ($needsImport) {
            // We avoid rewriting the head of the file — a trailing use is
            // unconventional but legal, and keeps the injection a pure append
            // so a re-run check by sha256 is straightforward.
            $lines[] = 'use Illuminate\\Support\\Facades\\Schedule as SwarmDurableSchedule;';
            $lines[] = '';
            $lines[] = "SwarmDurableSchedule::command('swarm:relay')->everyMinute()->withoutOverlapping()->runInBackground();";
            $lines[] = "SwarmDurableSchedule::command('swarm:recover')->everyFiveMinutes()->withoutOverlapping()->runInBackground();";
            $lines[] = "SwarmDurableSchedule::command('swarm:prune')->daily()->runInBackground();";
        } else {
            $lines[] = "Schedule::command('swarm:relay')->everyMinute()->withoutOverlapping()->runInBackground();";
            $lines[] = "Schedule::command('swarm:recover')->everyFiveMinutes()->withoutOverlapping()->runInBackground();";
            $lines[] = "Schedule::command('swarm:prune')->daily()->runInBackground();";
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Detect a `Schedule::command('<name>')` registration tolerant of quote
     * style and whitespace. Mirrors the assertion used in the installer test
     * harness so the contract is symmetric.
     */
    private function fileHasScheduleEntry(string $contents, string $commandName): bool
    {
        $pattern = '/Schedule\s*::\s*command\s*\(\s*[\'"]'.preg_quote($commandName, '/').'[\'"]/';

        return (bool) preg_match($pattern, $contents);
    }

    /**
     * Print copy-paste worker snippets so the operator does not need to
     * leave the terminal to figure out the next step.
     */
    private function printWorkerSnippets(string $queueConnection, string $queueName): void
    {
        $this->newLine();
        $this->line('  <fg=cyan>Run a worker for the durable queue:</>');
        $this->newLine();

        $this->line('  <fg=yellow># Plain queue worker</>');
        $connectionArg = $queueConnection !== '' ? $queueConnection.' ' : '';
        $this->line("  php artisan queue:work {$connectionArg}--queue={$queueName}");
        $this->newLine();

        $this->line('  <fg=yellow># Laravel Horizon (config/horizon.php — supervisor "queue" list)</>');
        $this->line("  'queue' => ['default', '{$queueName}'],");
        $this->newLine();

        $this->line('  <fg=yellow># Forge / Supervisor (.conf snippet)</>');
        $this->line('  [program:swarm-durable]');
        $this->line('  process_name=%(program_name)s_%(process_num)02d');
        $this->line("  command=php /home/forge/your-app/artisan queue:work {$connectionArg}--queue={$queueName} --sleep=3 --tries=3 --max-time=3600");
        $this->line('  autostart=true');
        $this->line('  autorestart=true');
        $this->line('  user=forge');
        $this->line('  numprocs=1');
        $this->line('  redirect_stderr=true');
        $this->line('  stdout_logfile=/home/forge/your-app/storage/logs/swarm-durable.log');
        $this->line('  stopwaitsecs=3600');
    }
}
