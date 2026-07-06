<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Installer\SwarmInstallerTestCase;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

uses(SwarmInstallerTestCase::class);

/**
 * Feature tests for `swarm:install:memory`.
 *
 * The installer test harness materializes a fresh Laravel-shaped skeleton and
 * points the booted application at it (see tests/Installer/README.md). These
 * tests cover:
 *   - success path: prints driver + replay mode + next steps
 *   - idempotency: second run is a byte-for-byte no-op
 *   - warning (not failure) when driver is cache
 *   - warning when tables are missing and --skip-migrate is set
 *   - --migrate runs migrations and tables become present
 *   - non-interactive default: skips migrations without prompting
 *   - prints SWARM_MEMORY_REPLAY_MODE configuration
 *
 * The harness boots SwarmServiceProvider and uses an in-memory SQLite
 * database (no migrations run by default), so memory tables are absent
 * in every fresh test. Tests that verify the "tables already present"
 * path must run migrations explicitly via the --migrate flag.
 */
beforeEach(function () {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('swarm.persistence.driver', 'database');
});

test('swarm:install:memory prints driver, replay mode, and next steps', function () {
    $result = $this->runInstaller('swarm:install:memory', ['--skip-migrate' => true])
        ->assertSucceeded()
        ->assertOutputContains('Installing the Swarm Memory subsystem.')
        ->assertOutputContains('Memory driver')
        ->assertOutputContains('database')
        ->assertOutputContains('SWARM_MEMORY_REPLAY_MODE')
        ->assertOutputContains('frozen_view')
        ->assertOutputContains('Next steps')
        ->assertOutputContains('swarm:health');

    // The driver-gate passed (we hit the migration check, not the cache-driver
    // early-exit). With --skip-migrate on an empty DB the tables-missing warning
    // is the signal we reached the migration step rather than returning early.
    $result->assertOutputContains('swarm_memories');
});

test('swarm:install:memory is idempotent on a second run', function () {
    $this->runInstaller('swarm:install:memory', ['--skip-migrate' => true])
        ->assertSucceeded()
        ->twice()
        ->assertSecondRunIsNoOp();
});

test('swarm:install:memory warns (not fails) when the persistence driver is cache', function () {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('swarm.persistence.driver', 'cache');

    // Cache driver is a valid ephemeral workload — the command must succeed
    // with a warning rather than returning a non-zero exit code.
    $result = $this->runInstaller('swarm:install:memory', [])
        ->assertSucceeded()
        ->assertOutputContains('Memory driver is [cache]')
        ->assertOutputContains('entries will not be durable');

    // Even on the cache path we still print config and next steps.
    $result->assertOutputContains('SWARM_MEMORY_REPLAY_MODE')
        ->assertOutputContains('Next steps');
});

test('swarm:install:memory respects per-subsystem swarm.memory.driver override', function () {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    // Global driver is cache but the memory subsystem is explicitly wired to database.
    $config->set('swarm.persistence.driver', 'cache');
    $config->set('swarm.memory.driver', 'database');

    // The per-subsystem override wins: installer proceeds to the migration check.
    $result = $this->runInstaller('swarm:install:memory', ['--skip-migrate' => true])
        ->assertSucceeded()
        ->assertOutputContains('Memory driver')
        ->assertOutputContains('database');

    // We should NOT see the cache-driver warning.
    expect($result->output)->not->toContain('entries will not be durable');
});

test('swarm:install:memory warns when memory tables are missing and --skip-migrate is set', function () {
    // The harness spins up an in-memory SQLite database with no migrations
    // run, so both memory tables are absent by default.
    $result = $this->runInstaller('swarm:install:memory', ['--skip-migrate' => true])
        ->assertSucceeded()
        ->assertOutputContains('Memory tables are missing')
        ->assertOutputContains('Run `php artisan migrate`');
});

test('swarm:install:memory --migrate runs migrations and reports tables present', function () {
    $result = $this->runInstaller('swarm:install:memory', ['--migrate' => true])
        ->assertSucceeded();

    // After running migrations the installer must report the tables are present.
    $result->assertOutputContains('Memory migrations: applied');

    // Running a second time must detect tables are present (not re-migrate).
    $this->runInstaller('swarm:install:memory', ['--skip-migrate' => true])
        ->assertSucceeded()
        ->assertOutputContains('Memory migrations: present');
});

test('swarm:install:memory skips migrations without prompting in --no-interaction mode', function () {
    // Without an explicit --migrate flag, non-interactive mode must not
    // attempt to run migrations — it warns and exits cleanly.
    $result = $this->runInstaller('swarm:install:memory', ['--no-interaction' => true])
        ->assertSucceeded()
        ->assertOutputContains('Skipping migrations');
});

test('swarm:install:memory prints the active SWARM_MEMORY_REPLAY_MODE', function () {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('swarm.memory.replay_mode', 'fresh_execution');

    $result = $this->runInstaller('swarm:install:memory', ['--skip-migrate' => true])
        ->assertSucceeded()
        ->assertOutputContains('fresh_execution')
        ->assertOutputContains('live memory on retry');
});

test('swarm:install:memory prints the MemoryReplay attribute hint in next steps', function () {
    $this->runInstaller('swarm:install:memory', ['--skip-migrate' => true])
        ->assertSucceeded()
        ->assertOutputContains('#[MemoryReplay')
        ->assertOutputContains('docs/memory.md');
});
