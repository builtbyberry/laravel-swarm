<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Install;

use BuiltByBerry\LaravelSwarm\LaravelSwarm;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Laravel\Pulse\Pulse;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

/**
 * `swarm:install` — the single command an operator runs after
 * `composer require builtbyberry/laravel-swarm`.
 *
 * This is the orchestrator. It does not duplicate the logic of the targeted
 * sub-installers (`swarm:install:durable`, `swarm:install:audit`,
 * `swarm:install:pulse`, `swarm:install:examples`); it dispatches into them
 * after asking the operator (or, in `--no-interaction` mode, after consulting
 * the matching flag) whether each one is wanted.
 *
 * What this command owns directly:
 *
 *   - Publishing `config/swarm.php` (idempotent — skips when already
 *     published, opts in to overwrite via `--force`).
 *   - Seeding the canonical set of Swarm env keys + safe defaults into `.env`
 *     and `.env.example` (additive — keys that already exist are left alone).
 *   - The database-vs-cache persistence choice: either runs `php artisan
 *     migrate` to create the swarm tables, or scaffolds a
 *     `LaravelSwarm::ignoreMigrations()` call into `AppServiceProvider::register()`
 *     behind a sentinel marker.
 *   - The `QUEUE_CONNECTION=sync` warning (the install does not mutate
 *     `config/queue.php` — that is out of scope by design).
 *   - The "you're ready" closing panel with the next-step doc + health-check
 *     command.
 */
#[AsCommand(name: 'swarm:install')]
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'swarm:install
        {--force : Overwrite existing config/swarm.php when re-publishing}
        {--persistence= : Persistence driver to seed (database|cache). Defaults to database interactively, no change otherwise.}
        {--migrate : Run migrations without prompting (database persistence)}
        {--skip-migrate : Skip running migrations even if pending (database persistence)}
        {--with-durable : Dispatch swarm:install:durable after the base install}
        {--without-durable : Skip swarm:install:durable in --no-interaction mode}
        {--with-audit : Dispatch swarm:install:audit after the base install}
        {--without-audit : Skip swarm:install:audit in --no-interaction mode}
        {--with-pulse : Dispatch swarm:install:pulse when Laravel Pulse is detected}
        {--without-pulse : Skip swarm:install:pulse in --no-interaction mode}
        {--with-examples : Dispatch swarm:install:examples (installs all starter examples)}
        {--without-examples : Skip swarm:install:examples in --no-interaction mode}';

    /** @var string */
    protected $description = 'Install Laravel Swarm into the host application (interactive walkthrough).';

    /**
     * Sentinel marker pair for the cache-only `LaravelSwarm::ignoreMigrations()`
     * scaffold injected into `AppServiceProvider::register()`. Same convention
     * the other v0.8.0 installers established post-#102.
     */
    private const IGNORE_MIGRATIONS_OPEN = '// swarm:install — cache-only persistence; do not edit between markers';

    private const IGNORE_MIGRATIONS_CLOSE = '// swarm:install — end cache-only persistence';

    /**
     * Indent used for the managed block inside register(). Matches the
     * stock AppServiceProvider convention (8 spaces — two levels deep from
     * class scope). Mirrors InstallAuditCommand::BODY_INDENT.
     */
    private const BODY_INDENT = '        ';

    /**
     * Canonical set of Swarm env keys + safe defaults. Maintained explicitly
     * (not scraped from `config/swarm.php`'s `env()` calls) because the
     * config file uses dynamic expressions that would be brittle to parse,
     * and because the canonical "what does a clean install need" list is a
     * smaller surface than the full env-driven configuration matrix.
     *
     * Keys already present in `.env` are left untouched by the seeding pass.
     *
     * @var array<string, string>
     */
    private const ENV_DEFAULTS = [
        'SWARM_PERSISTENCE_DRIVER' => 'database',
        'SWARM_TOPOLOGY' => 'sequential',
        'SWARM_TIMEOUT' => '300',
        'SWARM_MAX_AGENT_STEPS' => '10',
        'SWARM_AUDIT_FAILURE_POLICY' => 'queue',
        'SWARM_CAPTURE_INPUTS' => 'false',
        'SWARM_CAPTURE_OUTPUTS' => 'false',
        'SWARM_CAPTURE_ARTIFACTS' => 'false',
        'SWARM_CAPTURE_ACTIVE_CONTEXT' => 'false',
    ];

    public function handle(Application $app, ConfigRepository $config, Filesystem $files): int
    {
        $this->components->info('Installing Laravel Swarm.');
        $this->newLine();

        $summary = [];

        // 1. Publish config/swarm.php.
        $published = $this->publishConfig($app, $files);
        $summary[] = $published === 'published'
            ? 'Published config/swarm.php'
            : ($published === 'already' ? 'config/swarm.php already published (left as-is)' : 'config/swarm.php rewritten (--force)');

        // 2. Resolve persistence driver choice.
        $persistence = $this->resolvePersistenceDriver();
        if ($persistence === null) {
            return self::FAILURE;
        }

        // 3. Seed env keys (with the chosen persistence default applied).
        $envDefaults = self::ENV_DEFAULTS;
        $envDefaults['SWARM_PERSISTENCE_DRIVER'] = $persistence;

        $envAddedCount = $this->seedEnvFile($files, $app->basePath('.env'), $envDefaults);
        $exampleAddedCount = 0;
        $examplePath = $app->basePath('.env.example');
        if ($files->exists($examplePath)) {
            $exampleAddedCount = $this->seedEnvFile($files, $examplePath, $envDefaults);
        }
        $summary[] = $envAddedCount === 0
            ? '.env already has every Swarm key (no changes)'
            : "Appended {$envAddedCount} Swarm key(s) to .env"
                .($exampleAddedCount > 0 ? " ({$exampleAddedCount} to .env.example)" : '');

        // 4. Either run migrations or scaffold ignoreMigrations() for cache-only.
        if ($persistence === 'database') {
            $migrationResult = $this->handleDatabaseMigrations();
            if ($migrationResult === null) {
                return self::FAILURE;
            }
            $summary[] = $migrationResult;
        } else {
            $cacheResult = $this->scaffoldIgnoreMigrations($app, $files);
            if ($cacheResult === null) {
                return self::FAILURE;
            }
            $summary[] = $cacheResult;
        }

        // 5. Queue connection sanity check (warn only — never mutate config/queue.php).
        $this->warnIfSyncQueue($config);

        // 6. Offer sub-installers.
        $subResults = $this->dispatchSubInstallers($persistence);
        foreach ($subResults as $line) {
            $summary[] = $line;
        }

        // 7. Closing "you're ready" panel.
        $this->printFinalPanel($summary);

        return self::SUCCESS;
    }

    /**
     * Publish `config/swarm.php` into the host app's `config/` directory.
     *
     * We copy the package-shipped `config/swarm.php` directly rather than
     * dispatching `vendor:publish --tag=swarm-config` because the publish
     * map's destination is computed via `config_path()` at provider-boot
     * time. Testbench-backed installer harnesses (#92) reset the application
     * base path *after* boot, so the publish destination would point at the
     * test runner's stock config directory instead of the host app. A direct
     * copy resolves the destination at call time and works everywhere.
     *
     * Returns one of:
     *   'published' — fresh write
     *   'already'   — file already exists and `--force` was not passed
     *   'rewritten' — file existed and `--force` overwrote it
     */
    private function publishConfig(Application $app, Filesystem $files): string
    {
        $configPath = $app->basePath('config/swarm.php');
        $force = (bool) $this->option('force');

        if ($files->exists($configPath) && ! $force) {
            return 'already';
        }

        $existedBefore = $files->exists($configPath);

        $source = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'swarm.php';

        $files->ensureDirectoryExists(dirname($configPath));
        $files->copy($source, $configPath);

        return $existedBefore ? 'rewritten' : 'published';
    }

    /**
     * Resolve which persistence driver the operator wants seeded.
     *
     * Returns 'database' or 'cache', or null on a validation failure (which
     * has already been reported to the console).
     */
    private function resolvePersistenceDriver(): ?string
    {
        $flag = $this->option('persistence');

        if (is_string($flag) && $flag !== '') {
            $flag = strtolower($flag);
            if (! in_array($flag, ['database', 'cache'], true)) {
                $this->components->error(
                    "Invalid --persistence [{$flag}]. Choose one of: database, cache."
                );

                return null;
            }

            return $flag;
        }

        if (! $this->input->isInteractive()) {
            // CI default: database. Mirrors the canonical "production" path.
            // Cache-only is a niche opt-in; require an explicit flag for it.
            return 'database';
        }

        /** @var string $choice */
        $choice = select(
            label: 'Which persistence driver should Laravel Swarm use?',
            options: [
                'database' => 'database — durable runtime, audit outbox, full history (recommended)',
                'cache' => 'cache — fast, ephemeral only; no durable execution, no audit outbox',
            ],
            default: 'database',
            hint: 'You can change this later via SWARM_PERSISTENCE_DRIVER.',
        );

        return $choice;
    }

    /**
     * Append missing Swarm env keys + safe defaults to the given .env-shaped
     * file. Existing keys are left untouched (no clobbering of operator
     * overrides). Returns the number of keys newly appended.
     *
     * @param  array<string, string>  $defaults
     */
    private function seedEnvFile(Filesystem $files, string $path, array $defaults): int
    {
        $contents = $files->exists($path) ? (string) $files->get($path) : '';

        $existing = $this->parseEnvKeys($contents);
        $missing = array_diff_key($defaults, array_flip($existing));

        if ($missing === []) {
            return 0;
        }

        if ($contents !== '' && ! str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        $block = "\n# Laravel Swarm — added by swarm:install\n";
        foreach ($missing as $key => $value) {
            $block .= "{$key}={$value}\n";
        }

        $files->put($path, $contents.$block);

        return count($missing);
    }

    /**
     * Extract the set of env keys defined in a .env file. Tolerates comments
     * and surrounding whitespace. Quoting on the value side is irrelevant
     * for our "is this key already declared?" check.
     *
     * @return list<string>
     */
    private function parseEnvKeys(string $contents): array
    {
        $keys = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = ltrim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^([A-Z_][A-Z0-9_]*)\s*=/', $line, $m) === 1) {
                $keys[] = $m[1];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Run the package migrations (or defer based on flags / prompt).
     *
     * Returns a one-line summary on success, or null on a hard failure that
     * should halt the installer.
     */
    private function handleDatabaseMigrations(): ?string
    {
        if ((bool) $this->option('skip-migrate') === true) {
            $this->components->warn(
                'Skipping migrations. Run `php artisan migrate` before dispatching swarms.',
            );

            return 'Skipped migrations (--skip-migrate)';
        }

        $shouldMigrate = (bool) $this->option('migrate') === true
            || (! $this->input->isInteractive())
            || confirm(
                label: 'Run `php artisan migrate` now to create the swarm tables?',
                default: true,
            );

        if (! $shouldMigrate) {
            return 'Migrations deferred — run `php artisan migrate` before dispatching swarms';
        }

        try {
            $this->call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            $this->components->error('Migrations failed: '.$e->getMessage());

            return null;
        }

        return 'Ran swarm migrations';
    }

    /**
     * Scaffold a `LaravelSwarm::ignoreMigrations()` call into
     * `AppServiceProvider::register()` for the cache-only path.
     *
     * Mirrors the BODY_INDENT + relaxed-regex pattern established by
     * `InstallAuditCommand` post-#102. Sentinel-fenced so a re-run is a no-op.
     *
     * Returns a one-line summary or null on a hard failure.
     */
    private function scaffoldIgnoreMigrations(Application $app, Filesystem $files): ?string
    {
        $providerPath = $app->basePath('app/Providers/AppServiceProvider.php');

        if (! $files->exists($providerPath)) {
            $this->components->error(
                "Could not find [{$providerPath}]. Is this a Laravel application root?"
            );

            return null;
        }

        $contents = (string) $files->get($providerPath);

        if (str_contains($contents, self::IGNORE_MIGRATIONS_OPEN)) {
            return 'AppServiceProvider already declares LaravelSwarm::ignoreMigrations() (left as-is)';
        }

        $useLine = 'use '.LaravelSwarm::class.';';
        $needsImport = ! str_contains($contents, $useLine)
            && ! preg_match('/^use\s+'.preg_quote(LaravelSwarm::class, '/').'\s*;/m', $contents);

        if ($needsImport) {
            // Insert the import after the namespace declaration. Keeps the
            // file PSR-12 shaped without rewriting any existing imports.
            $contents = (string) preg_replace(
                '/(^namespace\s+[^;]+;\s*\R)/m',
                '$1'."\n".$useLine."\n",
                $contents,
                1,
            );
        }

        $block = $this->buildIgnoreMigrationsBlock();
        $rewritten = $this->insertBlockIntoRegister($contents, $block);

        if ($rewritten === null) {
            $this->components->error(
                'Could not locate a register() method in AppServiceProvider. '
                .'Add the snippet below by hand:'
            );
            $this->line('');
            $this->line($block);

            return null;
        }

        $files->put($providerPath, $rewritten);

        return 'Scaffolded LaravelSwarm::ignoreMigrations() into AppServiceProvider (cache-only persistence)';
    }

    /**
     * Build the sentinel-fenced cache-only scaffold body (column 0, no indent).
     * The inserter applies BODY_INDENT uniformly so the block lands at the
     * correct depth inside register().
     */
    private function buildIgnoreMigrationsBlock(): string
    {
        return self::IGNORE_MIGRATIONS_OPEN."\n"
            .LaravelSwarm::class.'::ignoreMigrations();'."\n"
            .self::IGNORE_MIGRATIONS_CLOSE;
    }

    /**
     * Insert the managed block at the top of `register()` body. Tolerates
     * both `register()` and `register(): void` signatures. Returns null when
     * no suitable register() method is found. Matches InstallAuditCommand's
     * indentation pattern: every block line gets BODY_INDENT.
     */
    private function insertBlockIntoRegister(string $contents, string $block): ?string
    {
        $pattern = '/(public function register\(\)\s*(?::\s*void\s*)?\{)([\t ]*\n)/';

        if (preg_match($pattern, $contents) !== 1) {
            return null;
        }

        $indented = self::BODY_INDENT.str_replace("\n", "\n".self::BODY_INDENT, $block);
        $replacement = '$1$2'.$indented."\n\n";

        $result = preg_replace($pattern, $replacement, $contents, 1);

        return is_string($result) ? $result : null;
    }

    /**
     * Inspect QUEUE_CONNECTION via config('queue.default'). Warn (do not
     * refuse) when the connection is `sync`. Writing to config/queue.php is
     * out of scope by design.
     */
    private function warnIfSyncQueue(ConfigRepository $config): void
    {
        $queueConnection = (string) $config->get('queue.default', 'sync');

        if ($queueConnection === 'sync') {
            $this->newLine();
            // Use the error-style output stream so structured log captures
            // still see the warning. Mirrors the v0.8.0 #102 convention.
            $this->output->getErrorStyle()->writeln(
                '  <fg=yellow>! QUEUE_CONNECTION=sync detected.</> '
                .'Swarm queued and durable execution require a real queue driver (database, redis, sqs).'
            );
            $this->output->getErrorStyle()->writeln(
                '    Switch via QUEUE_CONNECTION=database (or redis) in your .env, then re-run `php artisan swarm:install:durable`.'
            );
        }
    }

    /**
     * Offer (or auto-dispatch under --no-interaction) each sub-installer.
     * Returns a list of one-line summaries for the closing panel.
     *
     * @return list<string>
     */
    private function dispatchSubInstallers(string $persistence): array
    {
        $results = [];

        // Durable: only meaningful on the database persistence driver. We
        // still offer it on cache so the operator can hear "this is the
        // command to run when you flip the switch later", but in
        // --no-interaction mode we suppress the offer (the sub-installer
        // would refuse anyway).
        if ($this->shouldDispatch('durable', defaultYes: $persistence === 'database', helpHint: $persistence !== 'database' ? '(durable runtime requires the database persistence driver — skipping)' : null)) {
            $exit = $this->call('swarm:install:durable', $this->forwardingArgs());
            $results[] = $exit === self::SUCCESS
                ? 'Dispatched swarm:install:durable'
                : 'swarm:install:durable returned a non-zero exit code (review its output above)';
        }

        // Audit: always worth offering. Default yes interactively.
        if ($this->shouldDispatch('audit', defaultYes: true)) {
            $exit = $this->call('swarm:install:audit', $this->forwardingArgs());
            $results[] = $exit === self::SUCCESS
                ? 'Dispatched swarm:install:audit'
                : 'swarm:install:audit returned a non-zero exit code (review its output above)';
        }

        // Pulse: only offer when laravel/pulse is installed. Otherwise stay
        // silent — there is no nudge to install Pulse from the base installer.
        if ($this->pulseIsInstalled()) {
            if ($this->shouldDispatch('pulse', defaultYes: true)) {
                $exit = $this->call('swarm:install:pulse', $this->forwardingArgs());
                $results[] = $exit === self::SUCCESS
                    ? 'Dispatched swarm:install:pulse'
                    : 'swarm:install:pulse returned a non-zero exit code (review its output above)';
            }
        }

        // Examples: low-stakes scaffold, always worth offering. In
        // --no-interaction mode we forward --all so the sub-installer does
        // not error on its "pass --all or --example" precondition.
        if ($this->shouldDispatch('examples', defaultYes: true)) {
            $args = $this->forwardingArgs();
            if (! $this->input->isInteractive()) {
                $args['--all'] = true;
            }
            $exit = $this->call('swarm:install:examples', $args);
            $results[] = $exit === self::SUCCESS
                ? 'Dispatched swarm:install:examples'
                : 'swarm:install:examples returned a non-zero exit code (review its output above)';
        }

        return $results;
    }

    /**
     * Decide whether to dispatch a given sub-installer.
     *
     * Honors --with-<name> / --without-<name>. In interactive mode without an
     * override, prompts. In non-interactive mode without an override, returns
     * the supplied default.
     */
    private function shouldDispatch(string $name, bool $defaultYes, ?string $helpHint = null): bool
    {
        if ((bool) $this->option('with-'.$name) === true) {
            return true;
        }

        if ((bool) $this->option('without-'.$name) === true) {
            return false;
        }

        if (! $this->input->isInteractive()) {
            return $defaultYes;
        }

        if ($helpHint !== null) {
            $this->newLine();
            note($helpHint);
        }

        return confirm(
            label: "Run swarm:install:{$name} now?",
            default: $defaultYes,
        );
    }

    /**
     * Standard arg set forwarded to every sub-installer call. Keeps
     * --no-interaction propagation in one place.
     *
     * @return array<string, mixed>
     */
    private function forwardingArgs(): array
    {
        $args = [];

        if (! $this->input->isInteractive()) {
            $args['--no-interaction'] = true;
        }

        return $args;
    }

    /**
     * Pulse detection via class_exists. Mirrors the convention used by
     * `InstallPulseCommand::pulseIsInstalled()`. Protected so tests can
     * substitute a subclass that simulates the absent path.
     */
    protected function pulseIsInstalled(): bool
    {
        return class_exists(Pulse::class);
    }

    /**
     * Final "you're ready" closing panel: success summary + next-step pointers.
     *
     * @param  list<string>  $summary
     */
    private function printFinalPanel(array $summary): void
    {
        $this->newLine();
        $this->components->info('Laravel Swarm is installed.');

        foreach ($summary as $line) {
            $this->components->bulletList([$line]);
        }

        $this->newLine();
        $this->components->info('Next steps');
        $this->line('  • Verify the install: <comment>php artisan swarm:health</comment>');
        $this->line('  • Scaffold your first swarm: <comment>php artisan make:swarm:swarm ContentPipeline</comment>');
        $this->line('  • Getting started guide: <comment>docs/getting-started.md</comment>');
    }
}
