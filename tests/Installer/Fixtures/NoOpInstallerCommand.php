<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Installer\Fixtures;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Tiny installer fixture used to smoke-test the installer harness itself.
 *
 * Does the minimum a real `swarm:install*` command might do, so the harness
 * assertions and idempotency machinery are exercised end-to-end without
 * coupling to any of the real installer commands (which land in #85-#88, #90).
 *
 * Behaviors:
 *  - Writes `config/swarm-fixture.php` (only if missing).
 *  - Appends a single `SWARM_FIXTURE_INSTALLED` line to `.env` (only if missing).
 *  - Appends a `Schedule::command('fixture:run')->everyMinute();` block to
 *    `routes/console.php` (only if missing).
 *  - Appends `$this->app->bind(\stdClass::class, \ArrayObject::class);` to
 *    AppServiceProvider::register() (only if missing).
 *
 * The --force flag re-runs the appends even if the markers exist (used to
 * verify that the harness's idempotency assertion would *fail* loudly when
 * an installer is not actually idempotent).
 *
 * The --fail flag exits non-zero with a fixed error string (used to verify
 * the refusal-path helper).
 */
final class NoOpInstallerCommand extends Command
{
    protected $signature = 'swarm-fixture:install {--force : Re-write even if markers exist} {--fail : Exit non-zero to exercise the refusal path}';

    protected $description = 'Test fixture installer that exercises the InstallerTestCase harness.';

    public function handle(Filesystem $files): int
    {
        if ($this->option('fail') === true) {
            $this->error('Refusing to install: fixture --fail flag is set.');

            return 1;
        }

        $force = (bool) $this->option('force');
        $base = $this->laravel->basePath();

        $configPath = $base.'/config/swarm-fixture.php';
        if ($force === true || ! $files->exists($configPath)) {
            $files->ensureDirectoryExists(dirname($configPath));
            $files->put($configPath, "<?php\n\nreturn [\n    'installed' => true,\n];\n");
            $this->line('Created config/swarm-fixture.php.');
        }

        $envPath = $base.'/.env';
        if ($files->exists($envPath)) {
            $env = (string) $files->get($envPath);
            if ($force === true || ! str_contains($env, 'SWARM_FIXTURE_INSTALLED=')) {
                if (! str_ends_with($env, "\n")) {
                    $env .= "\n";
                }
                $env .= "SWARM_FIXTURE_INSTALLED=true\n";
                $files->put($envPath, $env);
                $this->line('Updated .env with SWARM_FIXTURE_INSTALLED=true.');
            }
        }

        $routesPath = $base.'/routes/console.php';
        if ($files->exists($routesPath)) {
            $routes = (string) $files->get($routesPath);
            if ($force === true || ! str_contains($routes, "Schedule::command('fixture:run')")) {
                if (! str_ends_with($routes, "\n")) {
                    $routes .= "\n";
                }
                $routes .= "\nSchedule::command('fixture:run')->everyMinute();\n";
                $files->put($routesPath, $routes);
                $this->line("Registered Schedule::command('fixture:run').");
            }
        }

        $providerPath = $base.'/app/Providers/AppServiceProvider.php';
        if ($files->exists($providerPath)) {
            $provider = (string) $files->get($providerPath);
            $bindingLine = '$this->app->bind(\\stdClass::class, \\ArrayObject::class);';
            if ($force === true || ! str_contains($provider, $bindingLine)) {
                $provider = (string) preg_replace(
                    '/(public function register\(\): void\s*\{\s*)(\/\/\s*)?/',
                    '$1        '.$bindingLine."\n        ",
                    $provider,
                    1,
                );
                $files->put($providerPath, $provider);
                $this->line('Registered stdClass binding in AppServiceProvider.');
            }
        }

        $this->info('Fixture installer complete.');

        return 0;
    }
}
