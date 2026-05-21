# Installer Test Harness

Shared infrastructure for testing the `swarm:install*` command family
introduced in v0.8.0 (issues #85, #86, #87, #88, #90).

Installer commands are tricky to unit-test because they mutate files in a
host Laravel skeleton — `config/`, `routes/console.php`, `app/Providers/AppServiceProvider.php`,
`.env`. This harness gives every installer test a clean, isolated, byte-level
sandbox so authors can focus on _"what should the installer write?"_ instead
of plumbing.

## What you get

`InstallerTestCase` is the base test case. Extend it via the standard Pest
`uses()` call:

```php
use BuiltByBerry\LaravelSwarm\Tests\Installer\InstallerTestCase;

uses(InstallerTestCase::class);
```

On `setUp()` the harness:

1. Spins up an Orchestra Testbench application (same providers the rest of
   the suite uses).
2. Materializes a temp directory shaped like a freshly-scaffolded Laravel 13
   app — `config/`, `routes/console.php`, `app/Providers/AppServiceProvider.php`,
   `.env`, `composer.json`, plus the usual `database/`, `resources/`,
   `storage/`, `bootstrap/`, `public/`, `tests/` directories.
3. Re-points the running application at the scratch skeleton via
   `$this->app->setBasePath(...)` so `app_path()`, `config_path()`,
   `base_path()`, `database_path()`, etc. all resolve into the fixture.
4. Tears the temp directory down in `tearDown()`. Each test gets its own
   uniquely-named skeleton — tests are parallel-safe.

The harness uses lightweight filesystem fixtures, not a real Laravel app
install. No extra `composer require-dev` entries are needed beyond
`orchestra/testbench`, which the package already depends on.

## Writing an installer test

```php
use BuiltByBerry\LaravelSwarm\Tests\Installer\InstallerTestCase;

uses(InstallerTestCase::class);

test('swarm:install:durable wires up the durable runtime', function () {
    $this->runInstaller('swarm:install:durable')
        ->assertSucceeded()
        ->assertOutputContains('Durable runtime installed.');

    $this->assertFileContains('config/swarm.php', "'driver' => 'database'");
    $this->assertEnvKey('SWARM_PERSISTENCE_DRIVER', 'database');
    $this->assertScheduleEntry('swarm:recover');
});
```

### Idempotency

Every installer must be safe to re-run. The harness has a one-liner:

```php
test('swarm:install:durable is idempotent', function () {
    $this->runInstaller('swarm:install:durable')
        ->assertSucceeded()
        ->twice()
        ->assertSecondRunIsNoOp();
});
```

`twice()` runs the installer a second time with the same arguments and
returns a `DoubleRunResult`. `assertSecondRunIsNoOp()` then verifies:

- the second run exited 0
- no file was created
- no file was deleted
- no file's contents (sha256) changed

If your installer needs to be re-runnable but has a `--force` flag that
intentionally overwrites, test `--force` separately — `assertSecondRunIsNoOp()`
is only for the default-mode contract.

### Refusal paths

Every installer should fail loudly when its preconditions are violated
(unsupported driver, already-installed-and-modified, missing `--force`, etc.).
The refusal helper checks both exit code and error message in one call:

```php
test('swarm:install:durable refuses on the cache persistence driver', function () {
    $this->writeSkeletonFile('config/swarm.php', "<?php return ['persistence' => ['driver' => 'cache']];");

    $this->assertInstallerFailsWith(
        'swarm:install:durable',
        [],
        'Durable runtime requires the database persistence driver',
    );
});
```

### Seeding the skeleton

Many installer scenarios need a pre-existing file in the skeleton — _"what
does the installer do when `config/swarm.php` already exists?"_. Use
`writeSkeletonFile()`:

```php
$this->writeSkeletonFile('config/swarm.php', file_get_contents(__DIR__.'/fixtures/old-swarm-config.php'));
```

`skeletonPath()` (no args) returns the absolute root, and
`skeletonPath('relative/path')` resolves a relative path inside it.

## Helpers reference

| Helper | Asserts |
|---|---|
| `runInstaller(string $command, array $arguments = []): InstallerRunResult` | Invokes the command. Returns a fluent result. |
| `assertInstallerFailsWith(string $command, array $arguments, string $needle): InstallerRunResult` | Exit code !== 0, output contains the fragment. |
| `assertFileContains(string $relative, string $needle): void` | Skeleton file exists and contains the fragment. |
| `assertEnvKey(string $key, string $value): void` | `.env` defines `KEY=value`. Tolerates quoting. |
| `assertScheduleEntry(string $name): void` | `routes/console.php` registers `Schedule::command('<name>')`. Tolerates whitespace and quote style. |
| `assertProviderBinding(string $abstract, string $concrete): void` | `AppServiceProvider` binds the pair via `bind()` or `singleton()`. Matches both `Foo::class` and `'Foo'` reference forms. |
| `writeSkeletonFile(string $relative, string $contents): void` | Seed a file into the skeleton (parents created as needed). |
| `skeletonPath(string $relative = ''): string` | Resolve an absolute path inside the skeleton. |
| `snapshotSkeleton(): array<string, string>` | Hash every file in the skeleton (relative path => sha256). Used internally by `assertSecondRunIsNoOp()`. |

`InstallerRunResult` also exposes:

- `assertSucceeded(): self` — exit code 0.
- `assertExitCode(int $expected): self` — exit code matches.
- `assertOutputContains(string $needle): self` — console output contains fragment.
- `twice(): DoubleRunResult` — run again, return the pair.
- Public readonly properties: `command`, `arguments`, `exitCode`, `output`,
  `skeletonSnapshot`.

## Registering a command that is not part of the package's default `commands()`

If you are testing an installer that has not yet been wired into
`SwarmServiceProvider::commands()`, register it manually:

```php
beforeEach(function () {
    $this->registerInstallerCommand(\App\Console\Commands\MyInstallerCommand::class);
});
```

Once the installer ships in the package's default command list, the
`registerInstallerCommand()` call can be deleted.

## Why this lives outside the default Pest binding

`tests/Pest.php` binds `tests/Feature` and `tests/Unit` to
`BuiltByBerry\LaravelSwarm\Tests\TestCase`, which is swarm-runtime-aware
(it boots a `SwarmEventRecorder`, configures persistence drivers, etc.).
Installer tests don't run swarms — they only assert on disk state — so they
opt in to `InstallerTestCase` per-file via `uses()`. This keeps the
installer harness lean and avoids forcing every installer test to pay for
the runtime-test setup.

## See also

- `tests/Installer/NoOpInstallerSmokeTest.php` — end-to-end smoke test against
  a tiny fixture installer.
- `tests/Installer/InstallerTestCaseHelpersTest.php` — unit tests for every
  helper above.
- `tests/Installer/Fixtures/NoOpInstallerCommand.php` — the fixture command
  the smoke test exercises. A useful reference for the shape a real installer
  command's mutations should take.
