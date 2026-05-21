<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Installer;

use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Base test case for `swarm:install*` command tests.
 *
 * Materializes a temp directory shaped like a minimal Laravel skeleton, points
 * the booted testbench application at it, runs the installer under test, and
 * exposes ergonomic assertions over the resulting filesystem state.
 *
 * Subclasses get:
 *
 *   - {@see runInstaller()}: invokes an Artisan command and returns an
 *     {@see InstallerRunResult} carrying the exit code, output, and a hash
 *     snapshot of the skeleton at completion time. Chainable into
 *     `->twice()->assertSecondRunIsNoOp()` to prove the installer is safe to
 *     re-run.
 *   - {@see assertInstallerFailsWith()}: convenience for refusal-path tests
 *     that assert non-zero exit + a specific error fragment.
 *   - File / env / schedule / provider-binding assertions that take paths
 *     relative to the skeleton root.
 *
 * The temp directory is destroyed in {@see tearDown()}, so tests are fully
 * isolated and parallel-safe. The harness uses lightweight filesystem
 * fixtures — it does not require any extra Laravel app dependency beyond the
 * `orchestra/testbench` we already depend on.
 *
 * @see InstallerRunResult
 * @see DoubleRunResult
 * @see tests/Installer/README.md
 */
abstract class InstallerTestCase extends Orchestra
{
    /** Absolute path to this test's scratch skeleton. */
    protected string $skeletonPath;

    /** Filesystem helper, instantiated lazily. */
    private ?Filesystem $files = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skeletonPath = $this->makeSkeletonDirectory();
        $this->materializeSkeleton($this->skeletonPath);

        // Re-point the booted application at our scratch skeleton so that
        // app_path() / config_path() / base_path() / etc. all resolve into
        // the fixture, exactly like an installer running in a real host app.
        $this->app->setBasePath($this->skeletonPath);
    }

    protected function tearDown(): void
    {
        if (isset($this->skeletonPath) && is_dir($this->skeletonPath)) {
            $this->filesystem()->deleteDirectory($this->skeletonPath);
        }

        parent::tearDown();
    }

    /**
     * Resolve the deferred command list registered against this app.
     *
     * Subclasses may override to register installer commands (or fixtures)
     * that are not part of the package's default `commands()` block.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BusServiceProvider::class,
            AiServiceProvider::class,
            SwarmServiceProvider::class,
        ];
    }

    /**
     * Keep the testbench app minimal — installer tests assert on disk state,
     * not on swarm runtime behavior. Subclasses may override to add config.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    /**
     * Register a command class against the running console kernel so the
     * harness can dispatch it via {@see runInstaller()}.
     *
     * Useful for harness self-tests (and any installer not yet wired into
     * the real {@see SwarmServiceProvider} `commands()` block).
     *
     * @param  class-string<Command>  $commandClass
     */
    public function registerInstallerCommand(string $commandClass): void
    {
        /** @var ConsoleKernel $kernel */
        $kernel = $this->app->make(ConsoleKernel::class);

        // Force the kernel to bootstrap before adding so getArtisan() exists.
        $kernel->call('list', [], new BufferedOutput);

        $kernel->registerCommand($this->app->make($commandClass));
    }

    /**
     * Run an installer command once against the scratch skeleton.
     *
     * Returns a fluent {@see InstallerRunResult}. The result captures a
     * sha256 snapshot of every file in the skeleton at completion so
     * `->twice()->assertSecondRunIsNoOp()` can later prove idempotency.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function runInstaller(string $command, array $arguments = []): InstallerRunResult
    {
        return $this->runInstallerOnce($command, $arguments);
    }

    /**
     * Internal: single-shot invocation used by both {@see runInstaller()}
     * and {@see InstallerRunResult::twice()}.
     *
     * @internal
     *
     * @param  array<string, mixed>  $arguments
     */
    public function runInstallerOnce(string $command, array $arguments = []): InstallerRunResult
    {
        /** @var ConsoleKernel $kernel */
        $kernel = $this->app->make(ConsoleKernel::class);
        $output = new BufferedOutput;

        $exitCode = $kernel->call($command, $arguments, $output);

        return new InstallerRunResult(
            command: $command,
            arguments: $arguments,
            exitCode: $exitCode,
            output: $output->fetch(),
            skeletonSnapshot: $this->snapshotSkeleton(),
            testCase: $this,
        );
    }

    /**
     * Refusal-path helper: assert the installer exits non-zero AND its output
     * contains the given error fragment.
     *
     * Use this for guarded installers (`--force` required, unsupported driver,
     * already-installed-and-modified, etc.) so every installer can lock down
     * its refusal contract with one line.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function assertInstallerFailsWith(string $command, array $arguments, string $expectedErrorFragment): InstallerRunResult
    {
        $result = $this->runInstallerOnce($command, $arguments);

        Assert::assertNotSame(
            0,
            $result->exitCode,
            "Installer [{$command}] was expected to fail but exited 0.\nOutput:\n{$result->output}",
        );

        Assert::assertStringContainsString(
            $expectedErrorFragment,
            $result->output,
            "Installer [{$command}] failed (exit {$result->exitCode}) but its output did not contain the expected error fragment.",
        );

        return $result;
    }

    /**
     * Assert a skeleton file (relative to the skeleton root) contains the
     * given fragment.
     *
     * Fails with a clear message if the file does not exist.
     */
    public function assertFileContains(string $relativePath, string $needle): void
    {
        $absolute = $this->skeletonPath($relativePath);

        Assert::assertFileExists(
            $absolute,
            "Expected installer to create [{$relativePath}] but the file does not exist.",
        );

        $contents = (string) file_get_contents($absolute);

        Assert::assertStringContainsString(
            $needle,
            $contents,
            "File [{$relativePath}] exists but does not contain expected fragment.",
        );
    }

    /**
     * Assert the `.env` file in the skeleton has the given key set to the
     * given (string) value. Matches `KEY=value` semantics, tolerating
     * surrounding whitespace and optional double-quoting.
     */
    public function assertEnvKey(string $key, string $expected): void
    {
        $envPath = $this->skeletonPath('.env');

        Assert::assertFileExists(
            $envPath,
            'Expected .env to exist in the skeleton but it does not.',
        );

        $contents = (string) file_get_contents($envPath);
        $actual = $this->readEnvKey($contents, $key);

        Assert::assertNotNull(
            $actual,
            "Expected .env to define [{$key}] but the key is not present.",
        );

        Assert::assertSame(
            $expected,
            $actual,
            "Expected .env key [{$key}] to equal [{$expected}], got [{$actual}].",
        );
    }

    /**
     * Assert `routes/console.php` contains a `Schedule::command('<name>')`
     * registration. Whitespace and quote style (single vs double) are
     * tolerated.
     */
    public function assertScheduleEntry(string $commandName): void
    {
        $routes = $this->skeletonPath('routes/console.php');

        Assert::assertFileExists(
            $routes,
            'Expected routes/console.php to exist in the skeleton but it does not.',
        );

        $contents = (string) file_get_contents($routes);

        // Tolerate Schedule::command('name'), Schedule::command("name"),
        // and arbitrary whitespace between :: and the opening paren.
        $pattern = '/Schedule\s*::\s*command\s*\(\s*[\'"]'.preg_quote($commandName, '/').'[\'"]/';

        Assert::assertMatchesRegularExpression(
            $pattern,
            $contents,
            "Expected routes/console.php to register Schedule::command('{$commandName}'), but no matching entry was found.",
        );
    }

    /**
     * Assert `app/Providers/AppServiceProvider.php` registers a container
     * binding of the given abstract to the given concrete.
     *
     * Matches both `$this->app->bind(Abstract::class, Concrete::class)` and
     * `$this->app->singleton(Abstract::class, Concrete::class)` forms (with
     * tolerant whitespace).
     */
    public function assertProviderBinding(string $abstract, string $concrete): void
    {
        $provider = $this->skeletonPath('app/Providers/AppServiceProvider.php');

        Assert::assertFileExists(
            $provider,
            'Expected app/Providers/AppServiceProvider.php to exist in the skeleton but it does not.',
        );

        $contents = (string) file_get_contents($provider);

        $abstractToken = $this->classReferencePattern($abstract);
        $concreteToken = $this->classReferencePattern($concrete);
        $pattern = '/\$this\s*->\s*app\s*->\s*(?:bind|singleton)\s*\(\s*'
            .$abstractToken
            .'\s*,\s*'
            .$concreteToken
            .'/';

        Assert::assertMatchesRegularExpression(
            $pattern,
            $contents,
            "Expected AppServiceProvider to bind [{$abstract}] => [{$concrete}], but no matching call was found.",
        );
    }

    /**
     * Resolve a path inside the scratch skeleton.
     */
    public function skeletonPath(string $relative = ''): string
    {
        return $relative === ''
            ? $this->skeletonPath
            : $this->skeletonPath.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }

    /**
     * Drop a file into the skeleton at a relative path (parents created on
     * demand). Useful for prepping installer scenarios — e.g. "what does the
     * installer do when config/swarm.php already exists?".
     */
    public function writeSkeletonFile(string $relative, string $contents): void
    {
        $absolute = $this->skeletonPath($relative);
        $directory = dirname($absolute);

        if (! is_dir($directory)) {
            $this->filesystem()->makeDirectory($directory, recursive: true);
        }

        file_put_contents($absolute, $contents);
    }

    /**
     * Build the minimal Laravel-like skeleton at the given path.
     *
     * The shape mirrors what a freshly-scaffolded Laravel 13 app exposes to
     * an installer: config/, routes/console.php, app/Providers/AppServiceProvider.php,
     * .env, composer.json. Empty directories that installers commonly need
     * (database/, resources/, storage/, bootstrap/) are also created.
     */
    protected function materializeSkeleton(string $base): void
    {
        $fs = $this->filesystem();

        foreach ([
            'app/Providers',
            'app/Console',
            'app/Http',
            'app/Models',
            'bootstrap/cache',
            'config',
            'database/migrations',
            'database/seeders',
            'database/factories',
            'public',
            'resources/views',
            'routes',
            'storage/app',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
            'stubs',
            'tests/Feature',
            'tests/Unit',
        ] as $subdir) {
            $fs->ensureDirectoryExists($base.DIRECTORY_SEPARATOR.$subdir);
        }

        file_put_contents($base.'/.env', $this->starterEnv());
        file_put_contents($base.'/.env.example', $this->starterEnv());
        file_put_contents($base.'/composer.json', $this->starterComposerJson());
        file_put_contents($base.'/routes/console.php', $this->starterConsoleRoutes());
        file_put_contents(
            $base.'/app/Providers/AppServiceProvider.php',
            $this->starterAppServiceProvider(),
        );
    }

    /**
     * Capture a sha256-per-file snapshot of every file in the skeleton.
     *
     * Used by the idempotency double-run helper to prove a re-run does not
     * mutate disk. Directories are intentionally not tracked — installers
     * are allowed to ensure directories exist; they should not modify file
     * contents on a re-run.
     *
     * @return array<string, string> relative path => sha256
     */
    public function snapshotSkeleton(): array
    {
        if (! is_dir($this->skeletonPath)) {
            return [];
        }

        $snapshot = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->skeletonPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $prefixLength = strlen($this->skeletonPath) + 1;

        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            if (! $info->isFile()) {
                continue;
            }

            $absolute = $info->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, $prefixLength));
            $hash = hash_file('sha256', $absolute);

            if ($hash !== false) {
                $snapshot[$relative] = $hash;
            }
        }

        ksort($snapshot);

        return $snapshot;
    }

    private function makeSkeletonDirectory(): string
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-swarm-installer-'.bin2hex(random_bytes(8));

        if (is_dir($base)) {
            $this->filesystem()->deleteDirectory($base);
        }

        if (! mkdir($base, 0o755, true) && ! is_dir($base)) {
            throw new RuntimeException("Unable to create installer skeleton directory at [{$base}].");
        }

        return $base;
    }

    private function filesystem(): Filesystem
    {
        return $this->files ??= new Filesystem;
    }

    /**
     * Build a regex token that matches either a fully-qualified class
     * reference (`\Foo\Bar::class`, with or without leading slash) or a
     * single-quoted / double-quoted FQCN.
     *
     * Lets {@see assertProviderBinding()} accept whichever shape an installer
     * actually emits.
     */
    private function classReferencePattern(string $fqcn): string
    {
        $trimmed = ltrim($fqcn, '\\');
        $escaped = preg_quote($trimmed, '/');

        return '(?:\\\\?'.$escaped.'::class|[\'"]\\\\?'.$escaped.'[\'"])';
    }

    private function readEnvKey(string $envContents, string $key): ?string
    {
        $pattern = '/^\s*'.preg_quote($key, '/').'\s*=\s*(.*)$/m';

        if (preg_match($pattern, $envContents, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        // Strip surrounding single or double quotes if present (matches
        // Laravel's own .env semantics).
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

    private function starterEnv(): string
    {
        return <<<'ENV'
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=sqlite

CACHE_STORE=database
QUEUE_CONNECTION=sync

ENV;
    }

    private function starterComposerJson(): string
    {
        return <<<'JSON'
{
    "name": "fixture/host-app",
    "type": "project",
    "require": {
        "php": "^8.5",
        "laravel/framework": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    }
}

JSON;
    }

    private function starterConsoleRoutes(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

PHP;
    }

    private function starterAppServiceProvider(): string
    {
        return <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

PHP;
    }
}
