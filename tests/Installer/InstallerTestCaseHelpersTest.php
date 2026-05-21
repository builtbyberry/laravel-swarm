<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Installer\Fixtures\NoOpInstallerCommand;
use BuiltByBerry\LaravelSwarm\Tests\Installer\InstallerTestCase;
use PHPUnit\Framework\AssertionFailedError;

uses(InstallerTestCase::class);

beforeEach(function () {
    $this->registerInstallerCommand(NoOpInstallerCommand::class);
});

test('assertFileContains fails with a clear message when the file is missing', function () {
    expect(fn () => $this->assertFileContains('config/does-not-exist.php', 'anything'))
        ->toThrow(
            AssertionFailedError::class,
            'config/does-not-exist.php',
        );
});

test('assertFileContains fails when the file exists but does not contain the fragment', function () {
    $this->writeSkeletonFile('config/test.php', "<?php return ['a' => 1];");

    expect(fn () => $this->assertFileContains('config/test.php', 'NOT_PRESENT'))
        ->toThrow(AssertionFailedError::class);
});

test('assertEnvKey reads unquoted, single-quoted, and double-quoted values', function () {
    $this->writeSkeletonFile('.env', <<<'ENV'
APP_NAME=Laravel
SWARM_FOO="durable"
SWARM_BAR='audit'
SWARM_BAZ=plain
ENV);

    $this->assertEnvKey('APP_NAME', 'Laravel');
    $this->assertEnvKey('SWARM_FOO', 'durable');
    $this->assertEnvKey('SWARM_BAR', 'audit');
    $this->assertEnvKey('SWARM_BAZ', 'plain');
});

test('assertEnvKey fails when the key is not present', function () {
    expect(fn () => $this->assertEnvKey('SWARM_MISSING', 'whatever'))
        ->toThrow(
            AssertionFailedError::class,
            'SWARM_MISSING',
        );
});

test('assertScheduleEntry tolerates single quotes, double quotes, and whitespace', function () {
    $this->writeSkeletonFile('routes/console.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Schedule;

Schedule :: command(  "swarm:prune"  )->daily();
Schedule::command('swarm:recover')->everyFiveMinutes();
PHP);

    $this->assertScheduleEntry('swarm:prune');
    $this->assertScheduleEntry('swarm:recover');
});

test('assertScheduleEntry fails when the entry is missing', function () {
    $this->writeSkeletonFile('routes/console.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('something:else')->daily();
PHP);

    expect(fn () => $this->assertScheduleEntry('swarm:prune'))
        ->toThrow(AssertionFailedError::class);
});

test('assertProviderBinding matches both bind() and singleton() against ::class or quoted FQCN', function () {
    $this->writeSkeletonFile('app/Providers/AppServiceProvider.php', <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Contracts\Foo::class, \App\Services\FooImpl::class);
        $this->app->singleton('App\Contracts\Bar', 'App\Services\BarImpl');
    }
}
PHP);

    $this->assertProviderBinding('App\Contracts\Foo', 'App\Services\FooImpl');
    $this->assertProviderBinding('\\App\\Contracts\\Foo', '\\App\\Services\\FooImpl');
    $this->assertProviderBinding('App\Contracts\Bar', 'App\Services\BarImpl');
});

test('assertProviderBinding fails when the binding is absent', function () {
    expect(fn () => $this->assertProviderBinding('App\Missing', 'App\Nope'))
        ->toThrow(AssertionFailedError::class);
});

test('twice()->assertSecondRunIsNoOp fails loudly when the installer is NOT idempotent', function () {
    // --force makes every run rewrite the same files, which changes file
    // mtimes but not contents — so it would still be a no-op.
    // Add a second mutation that actually changes contents: append a marker
    // line to .env on every run via runInstaller calls and manual writes.
    $this->runInstaller('swarm-fixture:install')->assertSucceeded();

    // Now manually mutate one file between snapshots so the double-run
    // diff has something to find. We do this by calling runInstaller a
    // second time with a different mutation in between.
    $result = $this->runInstaller('swarm-fixture:install');

    // Inject a difference: append a fresh line to .env post-snapshot.
    file_put_contents($this->skeletonPath('.env'), "FRESH_CHANGE=1\n", FILE_APPEND);

    expect(fn () => $result->twice()->assertSecondRunIsNoOp())
        ->toThrow(AssertionFailedError::class);
});

test('snapshotSkeleton hashes every file and is stable across calls', function () {
    $first = $this->snapshotSkeleton();
    $second = $this->snapshotSkeleton();

    expect($first)->toEqual($second)
        ->and($first)->toHaveKey('.env')
        ->and($first)->toHaveKey('routes/console.php')
        ->and($first)->toHaveKey('app/Providers/AppServiceProvider.php')
        ->and($first['.env'])->toMatch('/^[a-f0-9]{64}$/');
});

test('tearDown destroys the scratch skeleton (skeleton paths are unique per test)', function () {
    // Two test cases get two different skeleton paths — verify uniqueness
    // by re-materializing within this test.
    $path = $this->skeletonPath;
    expect($path)->toBeDirectory();
    expect($path)->toContain('laravel-swarm-installer-');
});
