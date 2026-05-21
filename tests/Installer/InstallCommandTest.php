<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Installer\Fixtures\PulseAbsentInstallCommand;
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

    // The sentinel-fenced managed block lands in .env so re-runs and human
    // readers can tell where the block came from, and so future installer
    // runs can extend the block in place rather than emitting duplicates.
    $envContents = (string) file_get_contents($this->skeletonPath('.env'));
    expect($envContents)->toContain('# swarm:install — managed env keys (do not edit between markers)')
        ->and($envContents)->toContain('# end swarm:install env keys');

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

test('swarm:install leaves operator-overridden non-persistence env values untouched', function () {
    // Pre-seed explicit overrides the operator already set for non-persistence
    // keys (SWARM_TIMEOUT, SWARM_AUDIT_FAILURE_POLICY). The persistence-driver
    // mismatch contract is exercised separately below.
    $envPath = $this->skeletonPath('.env');
    $existing = (string) file_get_contents($envPath);
    file_put_contents($envPath, $existing."\nSWARM_TIMEOUT=42\nSWARM_AUDIT_FAILURE_POLICY=halt\n");

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
    $this->assertEnvKey('SWARM_TIMEOUT', '42');
    $this->assertEnvKey('SWARM_AUDIT_FAILURE_POLICY', 'halt');

    // Missing keys still get appended.
    $this->assertEnvKey('SWARM_TOPOLOGY', 'sequential');
    $this->assertEnvKey('SWARM_PERSISTENCE_DRIVER', 'database');
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

test('swarm:install refuses when --persistence flag conflicts with existing .env value', function () {
    // Pre-seed an explicit SWARM_PERSISTENCE_DRIVER value the operator set
    // earlier (or that was committed to the .env). The installer must refuse
    // rather than silently take the migrate path while the runtime keeps
    // reading cache from .env — that's a real footgun (F1 in PR #103 review).
    $envPath = $this->skeletonPath('.env');
    $existing = (string) file_get_contents($envPath);
    file_put_contents($envPath, $existing."\nSWARM_PERSISTENCE_DRIVER=cache\n");

    $this->assertInstallerFailsWith(
        'swarm:install',
        [
            '--persistence' => 'database',
            '--skip-migrate' => true,
            '--without-durable' => true,
            '--without-audit' => true,
            '--without-pulse' => true,
            '--without-examples' => true,
            '--no-interaction' => true,
        ],
        'Mismatch: --persistence=database but .env declares SWARM_PERSISTENCE_DRIVER=cache',
    );

    // The operator's existing value must NOT have been mutated on the
    // refusal path.
    $this->assertEnvKey('SWARM_PERSISTENCE_DRIVER', 'cache');
});

test('swarm:install --force-env overwrites the existing SWARM_PERSISTENCE_DRIVER on mismatch', function () {
    $envPath = $this->skeletonPath('.env');
    $existing = (string) file_get_contents($envPath);
    file_put_contents($envPath, $existing."\nSWARM_PERSISTENCE_DRIVER=cache\n");

    $this->runInstaller('swarm:install', [
        '--persistence' => 'database',
        '--force-env' => true,
        '--skip-migrate' => true,
        '--without-durable' => true,
        '--without-audit' => true,
        '--without-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ])
        ->assertSucceeded()
        ->assertOutputContains('Overwrote SWARM_PERSISTENCE_DRIVER');

    $this->assertEnvKey('SWARM_PERSISTENCE_DRIVER', 'database');
});

test('swarm:install extends the existing managed env block when only some keys are missing', function () {
    // Pre-seed an .env with the sentinel block + a partial subset of the
    // canonical Swarm keys. The installer must extend the existing block
    // in place rather than appending a second managed block (F2 in PR #103
    // review).
    $envPath = $this->skeletonPath('.env');
    $base = (string) file_get_contents($envPath);

    $partialBlock = "\n# swarm:install — managed env keys (do not edit between markers)\n"
        ."SWARM_PERSISTENCE_DRIVER=database\n"
        ."SWARM_TOPOLOGY=sequential\n"
        ."# end swarm:install env keys\n";
    file_put_contents($envPath, $base.$partialBlock);

    $this->runInstaller('swarm:install', [
        '--persistence' => 'database',
        '--skip-migrate' => true,
        '--without-durable' => true,
        '--without-audit' => true,
        '--without-pulse' => true,
        '--without-examples' => true,
        '--no-interaction' => true,
    ])->assertSucceeded();

    $envContents = (string) file_get_contents($envPath);

    // Exactly one OPEN sentinel and one CLOSE sentinel — no duplicates.
    expect(substr_count($envContents, '# swarm:install — managed env keys'))->toBe(1)
        ->and(substr_count($envContents, '# end swarm:install env keys'))->toBe(1);

    // The newly-missing keys are now present.
    $this->assertEnvKey('SWARM_TIMEOUT', '300');
    $this->assertEnvKey('SWARM_CAPTURE_INPUTS', 'false');
});

test('swarm:install refuses when both --with-durable and --without-durable are passed', function () {
    $this->assertInstallerFailsWith(
        'swarm:install',
        [
            '--persistence' => 'database',
            '--skip-migrate' => true,
            '--with-durable' => true,
            '--without-durable' => true,
            '--without-audit' => true,
            '--without-pulse' => true,
            '--without-examples' => true,
            '--no-interaction' => true,
        ],
        'Pass either --with-durable or --without-durable, not both.',
    );
});
