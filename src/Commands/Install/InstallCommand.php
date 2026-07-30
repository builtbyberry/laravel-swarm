<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Install;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\DetectsInteractiveConsole;
use BuiltByBerry\LaravelSwarm\LaravelSwarm;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
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
 * `swarm:install:examples`); it dispatches into them after asking the
 * operator (or, in `--no-interaction` mode, after consulting the matching
 * flag) whether each one is wanted.
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
    use DetectsInteractiveConsole;

    /** @var string */
    protected $signature = 'swarm:install
        {--force : Overwrite existing config/swarm.php when re-publishing}
        {--force-env : Overwrite an existing SWARM_PERSISTENCE_DRIVER value in .env when --persistence disagrees with it}
        {--persistence= : Persistence driver to seed (database|cache). Defaults to database interactively, no change otherwise.}
        {--migrate : Run migrations without prompting (database persistence)}
        {--skip-migrate : Skip running migrations even if pending (database persistence)}
        {--with-durable : Dispatch swarm:install:durable after the base install}
        {--without-durable : Skip swarm:install:durable in --no-interaction mode}
        {--with-audit : Dispatch swarm:install:audit after the base install}
        {--without-audit : Skip swarm:install:audit in --no-interaction mode}
        {--with-examples : Dispatch swarm:install:examples (installs all starter examples)}
        {--without-examples : Skip swarm:install:examples in --no-interaction mode}
        {--with-memory : Dispatch swarm:install:memory after the base install}
        {--without-memory : Skip swarm:install:memory in --no-interaction mode}';

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
        'SWARM_MEMORY_REPLAY_MODE' => 'frozen_view',
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

        $envPath = $app->basePath('.env');

        // 3a. Persistence-mode mismatch detection. If .env already declares a
        // SWARM_PERSISTENCE_DRIVER that disagrees with the resolved
        // $persistence, the operator's existing .env value would win the
        // runtime contest but the installer's branch selection (migrate vs
        // scaffold ignoreMigrations()) would silently follow the flag. That
        // divergence is a silent footgun — refuse and ask the operator to
        // either fix .env or opt in via --force-env.
        $existingEnvPersistence = $files->exists($envPath)
            ? $this->readEnvKey((string) $files->get($envPath), 'SWARM_PERSISTENCE_DRIVER')
            : null;

        if ($existingEnvPersistence !== null && $existingEnvPersistence !== $persistence) {
            if (! (bool) $this->option('force-env')) {
                $this->components->error(
                    "Mismatch: --persistence={$persistence} but .env declares "
                    ."SWARM_PERSISTENCE_DRIVER={$existingEnvPersistence}. "
                    .'Update .env to match (or pass --force-env to overwrite the existing value).'
                );

                return self::FAILURE;
            }

            // --force-env: overwrite the existing .env key in place before
            // the additive seed pass runs (which would otherwise leave the
            // operator's value untouched).
            $envContents = (string) $files->get($envPath);
            $envContents = $this->rewriteEnvKey($envContents, 'SWARM_PERSISTENCE_DRIVER', $persistence);
            $files->put($envPath, $envContents);
            $summary[] = "Overwrote SWARM_PERSISTENCE_DRIVER in .env (--force-env): {$existingEnvPersistence} → {$persistence}";
        }

        $envAddedCount = $this->seedEnvFile($files, $envPath, $envDefaults);
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
        try {
            $subResults = $this->dispatchSubInstallers($persistence);
        } catch (InvalidArgumentException) {
            // shouldDispatch() already wrote the conflict-error message to
            // the console; halt here so the closing panel never renders.
            return self::FAILURE;
        }
        foreach ($subResults as $line) {
            $summary[] = $line;
        }

        // 7. Closing "you're ready" panel.
        $this->printFinalPanel($summary);

        return self::SUCCESS;
    }

    /**
     * Publish every file under the `swarm-config` publish tag into the host
     * app's config directory.
     *
     * We resolve sources via `ServiceProvider::pathsToPublish()` (the standard
     * publish registry the package already declares) rather than hand-coding a
     * source path — so future config publishables under the same tag are
     * picked up automatically.
     *
     * We re-resolve each destination against the *current* `$app->basePath()`
     * instead of trusting the captured destination from the publish map,
     * because Testbench-backed installer harnesses (#92) reset the
     * application base path *after* provider boot — the captured destination
     * would point at the test runner's stock config directory. The
     * recomputed destination works in both Testbench and a real Laravel app.
     *
     * Returns one of:
     *   'published' — at least one file was a fresh write, none existed
     *   'already'   — every file already exists and `--force` was not passed
     *   'rewritten' — at least one file was overwritten via `--force`
     */
    private function publishConfig(Application $app, Filesystem $files): string
    {
        $force = (bool) $this->option('force');

        /** @var array<string, string> $paths */
        $paths = ServiceProvider::pathsToPublish(SwarmServiceProvider::class, 'swarm-config');

        if ($paths === []) {
            return 'already';
        }

        $configBase = $app->basePath('config');
        $anyWritten = false;
        $anyExisted = false;

        foreach ($paths as $source => $capturedDestination) {
            // Re-resolve the destination against the current base path —
            // the captured destination was computed at provider-boot time
            // and is stale under Testbench.
            $filename = basename($capturedDestination);
            $destination = $configBase.DIRECTORY_SEPARATOR.$filename;

            $existedBefore = $files->exists($destination);

            if ($existedBefore && ! $force) {
                $anyExisted = true;

                continue;
            }

            if ($existedBefore) {
                $anyExisted = true;
            }

            $files->ensureDirectoryExists(dirname($destination));
            $files->copy($source, $destination);
            $anyWritten = true;
        }

        if (! $anyWritten) {
            return 'already';
        }

        return $anyExisted ? 'rewritten' : 'published';
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

        if (! $this->consoleCanPrompt()) {
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
     * Sentinel pair for the managed env block. seedEnvFile() inserts new
     * keys inside this fence on first run and extends within the fence on
     * subsequent runs — so a future package version adding defaults, or an
     * operator deleting a key, doesn't accumulate duplicate `# Laravel
     * Swarm` headers in .env.
     */
    private const ENV_BLOCK_OPEN = '# swarm:install — managed env keys (do not edit between markers)';

    private const ENV_BLOCK_CLOSE = '# end swarm:install env keys';

    /**
     * Add missing Swarm env keys + safe defaults to the given .env-shaped
     * file, fenced inside a sentinel-marked managed block. Existing keys
     * (whether inside the block or elsewhere in the file) are left
     * untouched — no clobbering of operator overrides. Returns the number of
     * keys newly written.
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

        if (str_contains($contents, self::ENV_BLOCK_OPEN)) {
            // Extend the existing managed block in place. The lazy `(.*?)`
            // captures up to (but not including) the final newline before
            // the CLOSE sentinel, so the extension needs to lead with a
            // newline to keep KEY=value lines on separate lines.
            $pattern = '/('.preg_quote(self::ENV_BLOCK_OPEN, '/').'\R)(.*?)(\R'.preg_quote(self::ENV_BLOCK_CLOSE, '/').')/s';

            $extension = "\n";
            foreach ($missing as $key => $value) {
                $extension .= "{$key}={$value}\n";
            }
            $extension = rtrim($extension, "\n");

            $rewritten = (string) preg_replace_callback(
                $pattern,
                static fn (array $m): string => $m[1].$m[2].$extension.$m[3],
                $contents,
                1,
            );

            $files->put($path, $rewritten);
        } else {
            // Append a fresh sentinel-fenced block at the end of the file.
            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }

            $block = "\n".self::ENV_BLOCK_OPEN."\n";
            foreach ($missing as $key => $value) {
                $block .= "{$key}={$value}\n";
            }
            $block .= self::ENV_BLOCK_CLOSE."\n";

            $files->put($path, $contents.$block);
        }

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
     * Read a single env key's value from .env-shaped contents. Returns null
     * when the key is not present. Strips surrounding single/double quotes
     * for the value (matches Laravel's own .env semantics).
     */
    private function readEnvKey(string $contents, string $key): ?string
    {
        $pattern = '/^\s*'.preg_quote($key, '/').'\s*=\s*(.*)$/m';

        if (preg_match($pattern, $contents, $m) !== 1) {
            return null;
        }

        $value = trim($m[1]);

        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Rewrite a single env key's value in place. Used by the --force-env
     * branch of the persistence-mismatch detector to overwrite an existing
     * SWARM_PERSISTENCE_DRIVER value before the additive seeding pass runs.
     */
    private function rewriteEnvKey(string $contents, string $key, string $value): string
    {
        $pattern = '/^(\s*'.preg_quote($key, '/').'\s*=).*$/m';

        $rewritten = preg_replace($pattern, '$1'.$value, $contents, 1);

        return is_string($rewritten) ? $rewritten : $contents;
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
            || (! $this->consoleCanPrompt())
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

        // Memory: always worth offering. Default yes interactively.
        if ($this->shouldDispatch('memory', defaultYes: true)) {
            $exit = $this->call('swarm:install:memory', $this->forwardingArgs());
            $results[] = $exit === self::SUCCESS
                ? 'Dispatched swarm:install:memory'
                : 'swarm:install:memory returned a non-zero exit code (review its output above)';
        }

        // Examples: low-stakes scaffold, always worth offering. In
        // --no-interaction mode we forward --all so the sub-installer does
        // not error on its "pass --all or --example" precondition.
        if ($this->shouldDispatch('examples', defaultYes: true)) {
            $args = $this->forwardingArgs();
            if (! $this->consoleCanPrompt()) {
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
     * Honors --with-<name> / --without-<name>. Refuses if both are passed
     * (matches the conflicting-flag pattern used by InstallExamplesCommand).
     * In interactive mode without an override, prompts. In non-interactive
     * mode without an override, returns the supplied default.
     *
     * @throws InvalidArgumentException When both --with-<name> and
     *                                  --without-<name> flags are passed.
     */
    private function shouldDispatch(string $name, bool $defaultYes, ?string $helpHint = null): bool
    {
        $with = (bool) $this->option('with-'.$name);
        $without = (bool) $this->option('without-'.$name);

        if ($with && $without) {
            $this->components->error(
                "Pass either --with-{$name} or --without-{$name}, not both."
            );

            throw new InvalidArgumentException(
                "Conflicting flags: --with-{$name} and --without-{$name}."
            );
        }

        if ($with) {
            return true;
        }

        if ($without) {
            return false;
        }

        if (! $this->consoleCanPrompt()) {
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

        if (! $this->consoleCanPrompt()) {
            $args['--no-interaction'] = true;
        }

        return $args;
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

        if ($summary !== []) {
            $this->components->bulletList($summary);
        }

        $this->newLine();
        $this->components->info('Next steps');
        $this->line('  • Verify the install: <comment>php artisan swarm:health</comment>');
        $this->line('  • Scaffold your first swarm: <comment>php artisan make:swarm:swarm ContentPipeline</comment>');
        $this->line('  • Getting started guide: <comment>docs/getting-started.md</comment>');
    }
}
