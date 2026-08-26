<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\Install\InstallDurableCommand;
use BuiltByBerry\LaravelSwarm\Tests\Installer\SwarmInstallerTestCase;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

uses(SwarmInstallerTestCase::class);

/**
 * Feature tests for `swarm:install:durable`.
 *
 * The installer test harness materializes a fresh Laravel-shaped skeleton and
 * points the booted application at it (see tests/Installer/README.md). These
 * tests cover:
 *   - success path: schedule entries injected, worker snippets printed
 *   - idempotency: second run is a byte-for-byte no-op
 *   - refusal on the cache persistence driver
 *   - refusal on sync queue (and the --allow-sync-queue bypass)
 *   - --queue flag overrides the printed queue name
 *
 * The harness boots SwarmServiceProvider, so `swarm.persistence.driver`
 * defaults from env('SWARM_PERSISTENCE_DRIVER', 'cache'). Each test sets the
 * driver via config() rather than env() because env() is captured at boot.
 */
beforeEach(function () {
    // Default each test to the happy-path runtime config: database
    // persistence + a non-sync queue. Tests that want to exercise refusal
    // paths reset these explicitly below.
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('swarm.persistence.driver', 'database');
    $config->set('queue.default', 'database');

    // The default skeleton .env carries QUEUE_CONNECTION=sync, but the
    // installer reads config('queue.default'), not the file directly. We
    // align the two by also rewriting the env entry so anyone scanning the
    // .env after the test sees a consistent picture.
    $envPath = $this->skeletonPath('.env');
    if (file_exists($envPath)) {
        $env = (string) file_get_contents($envPath);
        $env = preg_replace('/^QUEUE_CONNECTION=.*$/m', 'QUEUE_CONNECTION=database', $env) ?? $env;
        file_put_contents($envPath, $env);
    }
});

test('swarm:install:durable wires up the durable runtime on the happy path', function () {
    $result = $this->runInstaller('swarm:install:durable', ['--skip-migrate' => true])
        ->assertSucceeded()
        ->assertOutputContains('Durable runtime installed.');

    // Confirms the persistence-driver gate printed an ok line.
    $result->assertOutputContains('Persistence driver: database');

    // Schedule entries land in routes/console.php with the idempotency marker.
    $this->assertFileContains('routes/console.php', InstallDurableCommand::SCHEDULE_BLOCK_MARKER);
    $this->assertScheduleEntry('swarm:relay');
    $this->assertScheduleEntry('swarm:recover');
    $this->assertScheduleEntry('swarm:prune');
    $this->assertFileContains('routes/console.php', "swarm:relay')->everyMinute()->withoutOverlapping(max(1, (int) ceil(config('swarm.commands.overlap.lease_seconds', 3600) / 60)))");
    $this->assertFileContains('routes/console.php', "swarm:recover')->everyFiveMinutes()->withoutOverlapping(max(1, (int) ceil(config('swarm.commands.overlap.lease_seconds', 3600) / 60)))");

    // Worker snippets exist so the operator can copy-paste rather than
    // dig through docs.
    $result->assertOutputContains('queue:work');
    $result->assertOutputContains('Horizon');
    $result->assertOutputContains('Supervisor');
    $result->assertOutputContains('--queue=swarm-durable');
});

test('swarm:install:durable is idempotent on a second run', function () {
    $this->runInstaller('swarm:install:durable', ['--skip-migrate' => true])
        ->assertSucceeded()
        ->twice()
        ->assertSecondRunIsNoOp();
});

test('swarm:install:durable refuses when the persistence driver is cache', function () {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('swarm.persistence.driver', 'cache');

    $this->assertInstallerFailsWith(
        'swarm:install:durable',
        ['--skip-migrate' => true],
        'Durable runtime requires the database persistence driver',
    );

    // The refusal must be early — no scheduler entries should have been
    // written into routes/console.php.
    $routes = (string) file_get_contents($this->skeletonPath('routes/console.php'));
    expect($routes)->not->toContain(InstallDurableCommand::SCHEDULE_BLOCK_MARKER);
});

test('swarm:install:durable refuses when QUEUE_CONNECTION=sync', function () {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('queue.default', 'sync');

    $this->assertInstallerFailsWith(
        'swarm:install:durable',
        ['--skip-migrate' => true],
        'Durable execution requires a real queue connection',
    );
});

test('swarm:install:durable proceeds on sync queue when --allow-sync-queue is passed', function () {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('queue.default', 'sync');

    $this->runInstaller('swarm:install:durable', [
        '--skip-migrate' => true,
        '--allow-sync-queue' => true,
    ])
        ->assertSucceeded()
        ->assertOutputContains('not safe for production');

    $this->assertScheduleEntry('swarm:relay');
});

test('swarm:install:durable --queue overrides the printed queue name', function () {
    $this->runInstaller('swarm:install:durable', [
        '--skip-migrate' => true,
        '--queue' => 'critical-swarms',
    ])
        ->assertSucceeded()
        ->assertOutputContains('--queue=critical-swarms');
});

test('swarm:install:durable warns when durable tables are missing and --skip-migrate is set', function () {
    $result = $this->runInstaller('swarm:install:durable', ['--skip-migrate' => true])
        ->assertSucceeded();

    // The harness uses :memory: sqlite with no migrations run, so the
    // durable tables are absent. With --skip-migrate the installer must
    // continue but warn loudly.
    $result->assertOutputContains('Durable runtime tables are missing')
        ->assertOutputContains('Run `php artisan migrate`');
});

test('swarm:install:durable does not duplicate schedule entries that the user already wrote by hand', function () {
    // Pre-seed routes/console.php with manually written entries (no marker).
    $existing = <<<'PHP'
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:relay')->everyMinute();
Schedule::command('swarm:recover')->everyFiveMinutes();
Schedule::command('swarm:prune')->daily();

PHP;
    $this->writeSkeletonFile('routes/console.php', $existing);

    $result = $this->runInstaller('swarm:install:durable', ['--skip-migrate' => true])
        ->assertSucceeded()
        ->assertOutputContains('Scheduler entries: already present');

    // The marker must not be injected since the entries already exist.
    $routes = (string) file_get_contents($this->skeletonPath('routes/console.php'));
    expect($routes)->not->toContain(InstallDurableCommand::SCHEDULE_BLOCK_MARKER);

    // And the file should not have grown an extra Schedule::command line for
    // any of the three managed commands.
    expect(substr_count($routes, "Schedule::command('swarm:relay')"))->toBe(1)
        ->and(substr_count($routes, "Schedule::command('swarm:recover')"))->toBe(1)
        ->and(substr_count($routes, "Schedule::command('swarm:prune')"))->toBe(1);
});
