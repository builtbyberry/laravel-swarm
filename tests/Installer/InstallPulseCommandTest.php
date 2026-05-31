<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\Install\InstallPulseCommand;
use BuiltByBerry\LaravelSwarm\Tests\Installer\InstallerTestCase;

uses(InstallerTestCase::class);

/**
 * Minimal stand-in for the stock laravel/pulse config so we can assert that
 * the installer's recorders block is dropped inside the 'recorders' array
 * without depending on the real package's full file shape.
 */
function stubPulseConfig(): string
{
    return <<<'PHP'
<?php

return [
    'enabled' => true,
    'recorders' => [
        'Laravel\\Pulse\\Recorders\\Exceptions' => [
            'enabled' => true,
        ],
    ],
];
PHP;
}

function stubDashboardView(): string
{
    return <<<'BLADE'
<x-pulse>
    <livewire:pulse.servers cols="full" />
</x-pulse>
BLADE;
}

/**
 * Replacement command that pretends Pulse is not installed. Used to exercise
 * the refusal path; production detection is via `class_exists()` which is
 * always true in the test suite (laravel/pulse is a dev dep).
 */
final class InstallPulseCommandWithoutPulse extends InstallPulseCommand
{
    protected function pulseIsInstalled(): bool
    {
        return false;
    }
}

it('refuses with an actionable hint when Pulse is not installed', function () {
    // Register the no-pulse variant under the same signature so the harness
    // resolves it instead of the real command for this test only.
    $this->registerInstallerCommand(InstallPulseCommandWithoutPulse::class);

    $result = $this->assertInstallerFailsWith(
        'swarm:install:pulse',
        ['--no-interaction' => true],
        'Laravel Pulse is not installed.',
    );

    expect($result->output)
        ->toContain('composer require laravel/pulse')
        ->toContain('vendor:publish');
});

it('refuses when config/pulse.php is not yet published', function () {
    $result = $this->assertInstallerFailsWith(
        'swarm:install:pulse',
        ['--no-interaction' => true],
        'config/pulse.php was not found.',
    );

    expect($result->output)->toContain('--tag=pulse-config');
});

it('rejects an unknown card on the --cards flag', function () {
    $this->writeSkeletonFile('config/pulse.php', stubPulseConfig());

    $this->assertInstallerFailsWith(
        'swarm:install:pulse',
        ['--no-interaction' => true, '--cards' => 'runs,bogus'],
        'Unknown card(s): bogus',
    );
});

it('wires up recorders and the default card set on a clean skeleton', function () {
    $this->writeSkeletonFile('config/pulse.php', stubPulseConfig());

    $this->runInstaller('swarm:install:pulse', ['--no-interaction' => true])
        ->assertSucceeded()
        ->assertOutputContains('Registered SwarmRuns + SwarmStepDurations')
        ->assertOutputContains('Injected swarm cards');

    // Recorders edit
    $this->assertFileContains('config/pulse.php', 'swarm:install:pulse recorders');
    $this->assertFileContains('config/pulse.php', 'SwarmRuns::class');
    $this->assertFileContains('config/pulse.php', 'SwarmStepDurations::class');
    $this->assertFileContains('config/pulse.php', 'SwarmMemoryMetrics::class');
    $this->assertFileContains('config/pulse.php', 'PULSE_SWARM_RUNS_ENABLED');
    $this->assertFileContains('config/pulse.php', 'PULSE_SWARM_MEMORY_METRICS_ENABLED');

    // Dashboard published + cards inserted
    $this->assertFileContains('resources/views/vendor/pulse/dashboard.blade.php', 'swarm:install:pulse cards');
    $this->assertFileContains('resources/views/vendor/pulse/dashboard.blade.php', '<livewire:swarm.runs');
    $this->assertFileContains('resources/views/vendor/pulse/dashboard.blade.php', '<livewire:swarm.steps');
    $this->assertFileContains('resources/views/vendor/pulse/dashboard.blade.php', '<livewire:swarm.audit-outbox');
    $this->assertFileContains('resources/views/vendor/pulse/dashboard.blade.php', '<livewire:swarm.memory');

    // .bak written for the pre-existing config; the dashboard is published
    // from the stock Pulse view first, then backed up before injection so
    // the operator can always recover the unmodified copy.
    expect(file_exists($this->skeletonPath('config/pulse.php.bak')))->toBeTrue();
    expect(file_exists($this->skeletonPath('resources/views/vendor/pulse/dashboard.blade.php.bak')))->toBeTrue();
});

it('honors --cards to restrict the dashboard tags written', function () {
    $this->writeSkeletonFile('config/pulse.php', stubPulseConfig());
    $this->writeSkeletonFile('resources/views/vendor/pulse/dashboard.blade.php', stubDashboardView());

    $this->runInstaller('swarm:install:pulse', [
        '--no-interaction' => true,
        '--cards' => 'runs,steps',
    ])->assertSucceeded();

    $dashboard = (string) file_get_contents($this->skeletonPath('resources/views/vendor/pulse/dashboard.blade.php'));
    expect($dashboard)
        ->toContain('<livewire:swarm.runs')
        ->toContain('<livewire:swarm.steps')
        ->not->toContain('<livewire:swarm.audit-outbox');

    // .bak is written when the dashboard already existed
    expect(file_exists($this->skeletonPath('resources/views/vendor/pulse/dashboard.blade.php.bak')))->toBeTrue();
});

it('is idempotent on re-run with the same arguments', function () {
    $this->writeSkeletonFile('config/pulse.php', stubPulseConfig());
    $this->writeSkeletonFile('resources/views/vendor/pulse/dashboard.blade.php', stubDashboardView());

    $this->runInstaller('swarm:install:pulse', ['--no-interaction' => true])
        ->assertSucceeded()
        ->twice()
        ->assertSecondRunIsNoOp();
});

it('preserves the pre-install .bak when re-run with --force', function () {
    $this->writeSkeletonFile('config/pulse.php', stubPulseConfig());
    $this->writeSkeletonFile('resources/views/vendor/pulse/dashboard.blade.php', stubDashboardView());

    $this->runInstaller('swarm:install:pulse', ['--no-interaction' => true])
        ->assertSucceeded();

    $originalBackup = (string) file_get_contents($this->skeletonPath('config/pulse.php.bak'));

    $this->runInstaller('swarm:install:pulse', [
        '--no-interaction' => true,
        '--cards' => 'runs',
        '--force' => true,
    ])->assertSucceeded();

    // .bak should still reflect the *original* pre-install state, not the
    // post-first-install state — that's the contract: backup once, never
    // clobber.
    expect((string) file_get_contents($this->skeletonPath('config/pulse.php.bak')))
        ->toBe($originalBackup);

    // --force should have rewritten the managed cards block to runs-only.
    $dashboard = (string) file_get_contents($this->skeletonPath('resources/views/vendor/pulse/dashboard.blade.php'));
    expect($dashboard)
        ->toContain('<livewire:swarm.runs')
        ->not->toContain('<livewire:swarm.steps')
        ->not->toContain('<livewire:swarm.audit-outbox');
});
