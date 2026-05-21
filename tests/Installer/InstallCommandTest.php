<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\Install\InstallCommand;
use BuiltByBerry\LaravelSwarm\Tests\Installer\InstallerTestCase;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

uses(InstallerTestCase::class);

/**
 * Feature tests for `swarm:install` — the orchestrator command.
 *
 * The installer test harness materializes a fresh Laravel-shaped skeleton and
 * points the booted application at it (see tests/Installer/README.md). These
 * tests cover:
 *   - happy path with --no-interaction --with-* flags (database persistence)
 *   - cache-only path scaffolds LaravelSwarm::ignoreMigrations()
 *   - idempotency: a second --no-interaction run is a byte-level no-op
 *   - .env seeding honors operator overrides
 *   - --persistence validation
 *   - sync-queue warning surfaces on stderr
 *   - --without-* short-circuits sub-installer dispatch
 *   - Pulse-absent path silently skips the pulse sub-installer
 *   - --force re-publishes config/swarm.php in place
 */
beforeEach(function () {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('queue.default', 'database');
});

/**
 * A subclass that pretends Laravel Pulse is not installed, even when it is on
 * the test runner's classpath. Used to assert the "pulse skipped silently"
 * contract without mucking with the autoloader.
 */
class PulseAbsentInstallCommand extends InstallCommand
{
    protected function pulseIsInstalled(): bool
    {
        return false;
    }
}

test('swarm:install runs the happy path with database persistence and seeds env keys', function () {
    $result = $this->runInstaller('swarm:install', [
        '--persistence' => 'database',
        '--skip-migrate' => true,
        '--without-durable' => true,
        '--without-audit' => true,
        '--without-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ])
        ->assertSucceeded()
        ->assertOutputContains('Installing Laravel Swarm.')
        ->assertOutputContains('Laravel Swarm is installed.')
        ->assertOutputContains('Next steps')
        ->assertOutputContains('php artisan swarm:health');

    // Config publish happened (vendor:publish writes config/swarm.php).
    expect(file_exists($this->skeletonPath('config/swarm.php')))->toBeTrue();

    // Env keys land in .env and .env.example with safe defaults.
    $this->assertEnvKey('SWARM_PERSISTENCE_DRIVER', 'database');
    $this->assertEnvKey('SWARM_TOPOLOGY', 'sequential');
    $this->assertEnvKey('SWARM_AUDIT_FAILURE_POLICY', 'queue');
    $this->assertEnvKey('SWARM_CAPTURE_INPUTS', 'false');

    $example = (string) file_get_contents($this->skeletonPath('.env.example'));
    expect($example)->toContain('SWARM_PERSISTENCE_DRIVER=database');

    // The managed header comment lands in .env so re-runs and human readers
    // can tell where the block came from.
    $envContents = (string) file_get_contents($this->skeletonPath('.env'));
    expect($envContents)->toContain('# Laravel Swarm — added by swarm:install');

    // --skip-migrate path surfaces the deferred-migration summary line.
    $result->assertOutputContains('Skipped migrations');
});

test('swarm:install --persistence=cache scaffolds LaravelSwarm::ignoreMigrations() in AppServiceProvider', function () {
    $this->runInstaller('swarm:install', [
        '--persistence' => 'cache',
        '--without-durable' => true,
        '--without-audit' => true,
        '--without-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ])->assertSucceeded();

    $provider = (string) file_get_contents($this->skeletonPath('app/Providers/AppServiceProvider.php'));

    expect($provider)
        ->toContain('// swarm:install — cache-only persistence; do not edit between markers')
        ->toContain('// swarm:install — end cache-only persistence')
        ->toContain('LaravelSwarm::ignoreMigrations()')
        ->toContain('use BuiltByBerry\\LaravelSwarm\\LaravelSwarm;');

    $this->assertEnvKey('SWARM_PERSISTENCE_DRIVER', 'cache');
});

test('swarm:install is idempotent on a second --no-interaction run', function () {
    $args = [
        '--persistence' => 'database',
        '--skip-migrate' => true,
        '--without-durable' => true,
        '--without-audit' => true,
        '--without-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ];

    $this->runInstaller('swarm:install', $args)
        ->assertSucceeded()
        ->twice()
        ->assertSecondRunIsNoOp();
});

test('swarm:install leaves operator-overridden env values untouched', function () {
    // Pre-seed an explicit override the operator already set.
    $envPath = $this->skeletonPath('.env');
    $existing = (string) file_get_contents($envPath);
    file_put_contents($envPath, $existing."\nSWARM_PERSISTENCE_DRIVER=cache\nSWARM_TIMEOUT=42\n");

    $this->runInstaller('swarm:install', [
        '--persistence' => 'database',
        '--skip-migrate' => true,
        '--without-durable' => true,
        '--without-audit' => true,
        '--without-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ])->assertSucceeded();

    // The operator's existing values must win.
    $this->assertEnvKey('SWARM_PERSISTENCE_DRIVER', 'cache');
    $this->assertEnvKey('SWARM_TIMEOUT', '42');

    // Missing keys still get appended.
    $this->assertEnvKey('SWARM_TOPOLOGY', 'sequential');
});

test('swarm:install --persistence rejects unknown drivers', function () {
    $this->assertInstallerFailsWith(
        'swarm:install',
        [
            '--persistence' => 'redis',
            '--no-interaction' => true,
        ],
        'Invalid --persistence',
    );
});

test('swarm:install warns when QUEUE_CONNECTION=sync', function () {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('queue.default', 'sync');

    $result = $this->runInstaller('swarm:install', [
        '--persistence' => 'database',
        '--skip-migrate' => true,
        '--without-durable' => true,
        '--without-audit' => true,
        '--without-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ])->assertSucceeded();

    $result->assertOutputContains('QUEUE_CONNECTION=sync detected');
    $result->assertOutputContains('swarm:install:durable');
});

test('swarm:install --with-examples dispatches the examples sub-installer', function () {
    $result = $this->runInstaller('swarm:install', [
        '--persistence' => 'database',
        '--skip-migrate' => true,
        '--without-durable' => true,
        '--without-audit' => true,
        '--without-pulse' => true,
        '--with-examples' => true,
        '--no-interaction' => true,
    ])->assertSucceeded();

    $result->assertOutputContains('Dispatched swarm:install:examples');

    // At least one example tree was copied into app/Ai/Swarms/.
    expect(is_dir($this->skeletonPath('app/Ai/Swarms')))->toBeTrue();
});

test('swarm:install --with-audit dispatches the audit sub-installer', function () {
    $result = $this->runInstaller('swarm:install', [
        '--persistence' => 'database',
        '--skip-migrate' => true,
        '--without-durable' => true,
        '--with-audit' => true,
        '--without-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ])->assertSucceeded();

    $result->assertOutputContains('Dispatched swarm:install:audit');

    // The audit sub-installer drops a sentinel into AppServiceProvider — that
    // is the on-disk proof the dispatch actually fired.
    $provider = (string) file_get_contents($this->skeletonPath('app/Providers/AppServiceProvider.php'));
    expect($provider)->toContain('swarm:install:audit');
});

test('swarm:install silently skips swarm:install:pulse when Pulse is not installed', function () {
    $this->registerInstallerCommand(PulseAbsentInstallCommand::class);

    $result = $this->runInstaller('swarm:install', [
        '--persistence' => 'database',
        '--skip-migrate' => true,
        '--without-durable' => true,
        '--without-audit' => true,
        '--with-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ])->assertSucceeded();

    // The pulse sub-installer should not have been dispatched. The "Pulse is
    // not installed" message that swarm:install:pulse emits when reached via
    // the regular surface must not appear here.
    expect($result->output)->not->toContain('Dispatched swarm:install:pulse');
});

test('swarm:install --force re-publishes config/swarm.php in place', function () {
    // First run publishes.
    $this->runInstaller('swarm:install', [
        '--persistence' => 'database',
        '--skip-migrate' => true,
        '--without-durable' => true,
        '--without-audit' => true,
        '--without-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ])->assertSucceeded();

    // Mutate config/swarm.php so we can prove --force overwrites.
    $configPath = $this->skeletonPath('config/swarm.php');
    file_put_contents($configPath, "<?php // tampered\n");

    $this->runInstaller('swarm:install', [
        '--persistence' => 'database',
        '--skip-migrate' => true,
        '--force' => true,
        '--without-durable' => true,
        '--without-audit' => true,
        '--without-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ])->assertSucceeded();

    $rewritten = (string) file_get_contents($configPath);
    expect($rewritten)->not->toContain('// tampered');
    expect($rewritten)->toContain('SWARM_PERSISTENCE_DRIVER');
});

test('swarm:install cache-only scaffold is idempotent', function () {
    $args = [
        '--persistence' => 'cache',
        '--without-durable' => true,
        '--without-audit' => true,
        '--without-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ];

    $this->runInstaller('swarm:install', $args)
        ->assertSucceeded()
        ->twice()
        ->assertSecondRunIsNoOp();
});
