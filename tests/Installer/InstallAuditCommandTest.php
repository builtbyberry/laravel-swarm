<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\ActorResolver;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Installer\InstallerTestCase;

uses(InstallerTestCase::class);

test('swarm:install:audit scaffolds the readable sink binding in AppServiceProvider', function () {
    $this->runInstaller('swarm:install:audit', ['--sink' => 'readable'])
        ->assertSucceeded()
        ->assertOutputContains('Installing the Swarm audit pipeline.')
        ->assertOutputContains('Next steps');

    $this->assertProviderBinding(SwarmAuditSink::class, ReadableSwarmAuditSink::class);
    $this->assertFileContains('app/Providers/AppServiceProvider.php', 'swarm:install:audit — managed bindings');
});

test('swarm:install:audit scaffolds the noop sink binding when requested', function () {
    $this->runInstaller('swarm:install:audit', ['--sink' => 'noop'])
        ->assertSucceeded();

    $this->assertProviderBinding(SwarmAuditSink::class, NoOpSwarmAuditSink::class);
});

test('swarm:install:audit scaffolds a TODO marker for a custom sink', function () {
    $this->runInstaller('swarm:install:audit', ['--sink' => 'custom'])
        ->assertSucceeded();

    $this->assertFileContains(
        'app/Providers/AppServiceProvider.php',
        'TODO(swarm:install:audit): bind your SwarmAuditSink implementation here.',
    );
});

test('swarm:install:audit refuses an unknown --sink value', function () {
    $this->assertInstallerFailsWith(
        'swarm:install:audit',
        ['--sink' => 'bogus'],
        'Invalid --sink',
    );
});

test('swarm:install:audit scaffolds optional contract stubs when --with-* flags are passed', function () {
    $this->runInstaller('swarm:install:audit', [
        '--sink' => 'readable',
        '--with-signer' => true,
        '--with-actor-resolver' => true,
        '--with-capture-policy' => true,
    ])->assertSucceeded();

    $this->assertFileContains(
        'app/Providers/AppServiceProvider.php',
        SwarmAuditSigner::class,
    );
    $this->assertFileContains(
        'app/Providers/AppServiceProvider.php',
        ActorResolver::class,
    );
    $this->assertFileContains(
        'app/Providers/AppServiceProvider.php',
        CapturePolicy::class,
    );
});

test('swarm:install:audit omits optional contract stubs when the flags are not set', function () {
    $this->runInstaller('swarm:install:audit', ['--sink' => 'readable'])
        ->assertSucceeded();

    $provider = (string) file_get_contents($this->skeletonPath('app/Providers/AppServiceProvider.php'));

    expect($provider)->not->toContain(SwarmAuditSigner::class)
        ->and($provider)->not->toContain('TODO(swarm:install:audit): bind your SwarmAuditSigner');
});

test('swarm:install:audit is idempotent on a second run', function () {
    $this->runInstaller('swarm:install:audit', ['--sink' => 'readable'])
        ->assertSucceeded()
        ->twice()
        ->assertSecondRunIsNoOp();
});

test('swarm:install:audit leaves AppServiceProvider untouched on re-run', function () {
    $this->runInstaller('swarm:install:audit', ['--sink' => 'readable'])
        ->assertSucceeded();

    $before = (string) file_get_contents($this->skeletonPath('app/Providers/AppServiceProvider.php'));

    $result = $this->runInstaller('swarm:install:audit', ['--sink' => 'readable']);
    $result->assertSucceeded()
        ->assertOutputContains('already contains a swarm:install:audit block');

    $after = (string) file_get_contents($this->skeletonPath('app/Providers/AppServiceProvider.php'));

    expect($after)->toBe($before);
});

test('swarm:install:audit prints the current failure policy and capture flags', function () {
    config()->set('swarm.audit.failure_policy', 'queue');
    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);

    $this->runInstaller('swarm:install:audit', ['--sink' => 'readable'])
        ->assertSucceeded()
        ->assertOutputContains('SWARM_AUDIT_FAILURE_POLICY')
        ->assertOutputContains('queue')
        ->assertOutputContains('SWARM_CAPTURE_INPUTS')
        ->assertOutputContains('SWARM_CAPTURE_OUTPUTS');
});

test('swarm:install:audit cross-links the verification commands in next steps', function () {
    $result = $this->runInstaller('swarm:install:audit', ['--sink' => 'readable'])
        ->assertSucceeded();

    expect($result->output)
        ->toContain('swarm:audit:status')
        ->and($result->output)->toContain('swarm:audit:reconcile')
        ->and($result->output)->toContain('swarm:trace');
});

test('swarm:install:audit defaults to a custom-sink TODO marker under --no-interaction', function () {
    $this->runInstaller('swarm:install:audit', ['--no-interaction' => true])
        ->assertSucceeded();

    $this->assertFileContains(
        'app/Providers/AppServiceProvider.php',
        'TODO(swarm:install:audit): bind your SwarmAuditSink implementation here.',
    );
});

test('swarm:install:audit refuses if AppServiceProvider is missing', function () {
    @unlink($this->skeletonPath('app/Providers/AppServiceProvider.php'));

    $this->assertInstallerFailsWith(
        'swarm:install:audit',
        ['--sink' => 'readable'],
        'Could not find',
    );
});

test('swarm:install:audit refuses when register() cannot be located', function () {
    $this->writeSkeletonFile(
        'app/Providers/AppServiceProvider.php',
        "<?php\n\nnamespace App\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass AppServiceProvider extends ServiceProvider\n{\n    // no register() method here\n}\n",
    );

    $this->assertInstallerFailsWith(
        'swarm:install:audit',
        ['--sink' => 'readable'],
        'Could not locate a register() method body',
    );
});

test('swarm:install:audit notes when persistence driver is not database', function () {
    config()->set('swarm.persistence.driver', 'cache');

    $this->runInstaller('swarm:install:audit', ['--sink' => 'readable'])
        ->assertSucceeded()
        ->assertOutputContains('Persistence driver is [cache]')
        ->assertOutputContains('log-and-swallow');
});
