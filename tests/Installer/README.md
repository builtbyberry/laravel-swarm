# Installer Test Harness

Tests for the `swarm:install*` command family (issues #85, #86, #87, #88,
#90) use `SwarmInstallerTestCase`, a thin swarm-specific specialization of
the shared harness in
[`builtbyberry/laravel-swarm-installer-testkit`](https://github.com/builtbyberry/laravel-swarm-installer-testkit).

The harness mechanics — skeleton materialization, `runInstaller()`,
`assertInstallerFailsWith()`, `assertFileContains()`/`assertEnvKey()`/
`assertScheduleEntry()`/`assertProviderBinding()`, `writeSkeletonFile()`,
the `->twice()->assertSecondRunIsNoOp()` idempotency helper, and
`registerInstallerCommand()` — are documented in that package's own README.
This file only covers what's specific to this repo.

## Using it

```php
use BuiltByBerry\LaravelSwarm\Tests\Installer\SwarmInstallerTestCase;

uses(SwarmInstallerTestCase::class);

test('swarm:install:durable wires up the durable runtime', function () {
    $this->runInstaller('swarm:install:durable')
        ->assertSucceeded()
        ->assertOutputContains('Durable runtime installed.');

    $this->assertFileContains('config/swarm.php', "'driver' => 'database'");
    $this->assertEnvKey('SWARM_PERSISTENCE_DRIVER', 'database');
    $this->assertScheduleEntry('swarm:recover');
});
```

`SwarmInstallerTestCase` (in this directory) supplies the service providers
(`BusServiceProvider`, `AiServiceProvider`, `SwarmServiceProvider`) the
`swarm:install*` commands need to boot; the environment defaults
(in-memory sqlite, array cache) come from the kit unchanged.

## Why this lives outside the default Pest binding

`tests/Pest.php` binds `tests/Feature` and `tests/Unit` to
`BuiltByBerry\LaravelSwarm\Tests\TestCase`, which is swarm-runtime-aware
(it boots a `SwarmEventRecorder`, configures persistence drivers, etc.).
Installer tests don't run swarms — they only assert on disk state — so they
opt in to `SwarmInstallerTestCase` per-file via `uses()`. This keeps the
installer harness lean and avoids forcing every installer test to pay for
the runtime-test setup.

## See also

- `builtbyberry/laravel-swarm-installer-testkit` README — the shared harness
  mechanics, idempotency helper, and generic self-tests
  (`NoOpInstallerSmokeTest`, `InstallerTestCaseHelpersTest`) now live there
