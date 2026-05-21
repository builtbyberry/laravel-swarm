<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Installer\Fixtures\NoOpInstallerCommand;
use BuiltByBerry\LaravelSwarm\Tests\Installer\InstallerTestCase;

uses(InstallerTestCase::class);

beforeEach(function () {
    $this->registerInstallerCommand(NoOpInstallerCommand::class);
});

test('the harness materializes a Laravel-shaped skeleton at the application base path', function () {
    expect($this->skeletonPath)->toBeDirectory()
        ->and($this->skeletonPath('config'))->toBeDirectory()
        ->and($this->skeletonPath('routes/console.php'))->toBeFile()
        ->and($this->skeletonPath('app/Providers/AppServiceProvider.php'))->toBeFile()
        ->and($this->skeletonPath('.env'))->toBeFile()
        ->and($this->skeletonPath('composer.json'))->toBeFile()
        ->and(app()->basePath())->toBe($this->skeletonPath)
        ->and(app_path('Providers/AppServiceProvider.php'))->toBe($this->skeletonPath('app/Providers/AppServiceProvider.php'))
        ->and(config_path('swarm-fixture.php'))->toBe($this->skeletonPath('config/swarm-fixture.php'));
});

test('runInstaller invokes the command, captures output, and returns an InstallerRunResult', function () {
    $result = $this->runInstaller('swarm-fixture:install');

    $result->assertSucceeded()
        ->assertOutputContains('Fixture installer complete.');

    expect($result->exitCode)->toBe(0)
        ->and($result->command)->toBe('swarm-fixture:install')
        ->and($result->skeletonSnapshot)->toBeArray()
        ->and($result->skeletonSnapshot)->toHaveKey('config/swarm-fixture.php');
});

test('the no-op fixture installer mutates the skeleton as expected', function () {
    $this->runInstaller('swarm-fixture:install')->assertSucceeded();

    $this->assertFileContains('config/swarm-fixture.php', "'installed' => true");
    $this->assertEnvKey('SWARM_FIXTURE_INSTALLED', 'true');
    $this->assertScheduleEntry('fixture:run');
    $this->assertProviderBinding(stdClass::class, ArrayObject::class);
});

test('the no-op fixture installer is idempotent on a second run', function () {
    $this->runInstaller('swarm-fixture:install')
        ->assertSucceeded()
        ->twice()
        ->assertSecondRunIsNoOp();
});

test('assertInstallerFailsWith captures non-zero exit and a specific error fragment', function () {
    $this->assertInstallerFailsWith(
        'swarm-fixture:install',
        ['--fail' => true],
        'Refusing to install',
    );
});
