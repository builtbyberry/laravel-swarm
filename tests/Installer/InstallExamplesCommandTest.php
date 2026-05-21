<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Installer\InstallerTestCase;

uses(InstallerTestCase::class);

/**
 * Tests for `swarm:install:examples` (#90) — the sub-installer that copies the
 * curated starter example pack (#89) from `stubs/examples/` into the host
 * Laravel app and rewrites the `{{ rootNamespace }}` placeholder.
 *
 * The harness skeleton ships with `App\` as its PSR-4 root in
 * `composer.json`, so every test below asserts the namespace rewrite landed
 * on `App\Ai\...` and `App\Console\Commands\...`.
 */
test('it discovers the bundled examples and installs --all in non-interactive mode', function () {
    $this->runInstaller('swarm:install:examples', ['--all' => true, '--no-interaction' => true])
        ->assertSucceeded()
        ->assertOutputContains('Installed example [sequential-blog-pipeline]')
        ->assertOutputContains('Installed example [parallel-research-fanout]')
        ->assertOutputContains('Installed example [durable-approval-workflow]');

    // The trees should land exactly as the stubs ship them.
    expect(file_exists($this->skeletonPath('app/Ai/Swarms/SequentialBlogPipeline/BlogPipeline.php')))->toBeTrue();
    expect(file_exists($this->skeletonPath('app/Ai/Agents/SequentialBlogPipeline/OutlineWriter.php')))->toBeTrue();
    expect(file_exists($this->skeletonPath('app/Console/Commands/SwarmExampleBlogPipelineCommand.php')))->toBeTrue();

    expect(file_exists($this->skeletonPath('app/Ai/Swarms/ParallelResearchFanout/ResearchFanout.php')))->toBeTrue();
    expect(file_exists($this->skeletonPath('app/Console/Commands/SwarmExampleResearchFanoutCommand.php')))->toBeTrue();

    expect(file_exists($this->skeletonPath('app/Ai/Swarms/DurableApprovalWorkflow/PolicyApprovalSwarm.php')))->toBeTrue();
    expect(file_exists($this->skeletonPath('app/Console/Commands/SwarmExampleApprovalWorkflowCommand.php')))->toBeTrue();

    // The package-internal README should not be copied into the user's app.
    expect(file_exists($this->skeletonPath('app/README.md')))->toBeFalse();
    expect(file_exists($this->skeletonPath('README.md')))->toBeFalse();
});

test('it rewrites {{ rootNamespace }} to the host app namespace', function () {
    $this->runInstaller('swarm:install:examples', ['--example' => ['sequential-blog-pipeline'], '--no-interaction' => true])
        ->assertSucceeded();

    $this->assertFileContains(
        'app/Ai/Swarms/SequentialBlogPipeline/BlogPipeline.php',
        'namespace App\Ai\Swarms\SequentialBlogPipeline;',
    );
    $this->assertFileContains(
        'app/Ai/Swarms/SequentialBlogPipeline/BlogPipeline.php',
        'use App\Ai\Agents\SequentialBlogPipeline\Drafter;',
    );
    $this->assertFileContains(
        'app/Console/Commands/SwarmExampleBlogPipelineCommand.php',
        'namespace App\Console\Commands;',
    );
    $this->assertFileContains(
        'app/Console/Commands/SwarmExampleBlogPipelineCommand.php',
        'use App\Ai\Swarms\SequentialBlogPipeline\BlogPipeline;',
    );

    // No raw placeholder must survive anywhere in the installed tree.
    $bytes = file_get_contents($this->skeletonPath('app/Ai/Swarms/SequentialBlogPipeline/BlogPipeline.php'));
    expect($bytes)->not->toContain('{{ rootNamespace }}');
});

test('it respects a non-App PSR-4 root from composer.json', function () {
    $this->writeSkeletonFile('composer.json', <<<'JSON'
{
    "name": "fixture/host-app",
    "autoload": {
        "psr-4": {
            "Acme\\Platform\\": "app/"
        }
    }
}
JSON);

    $this->runInstaller('swarm:install:examples', ['--example' => ['sequential-blog-pipeline'], '--no-interaction' => true])
        ->assertSucceeded();

    $this->assertFileContains(
        'app/Ai/Swarms/SequentialBlogPipeline/BlogPipeline.php',
        'namespace Acme\Platform\Ai\Swarms\SequentialBlogPipeline;',
    );
    $this->assertFileContains(
        'app/Console/Commands/SwarmExampleBlogPipelineCommand.php',
        'use Acme\Platform\Ai\Swarms\SequentialBlogPipeline\BlogPipeline;',
    );
});

test('it refuses to overwrite an existing file without --force', function () {
    $this->writeSkeletonFile(
        'app/Ai/Swarms/SequentialBlogPipeline/BlogPipeline.php',
        "<?php\n// user-edited\n",
    );

    $result = $this->runInstaller('swarm:install:examples', [
        '--example' => ['sequential-blog-pipeline'],
        '--no-interaction' => true,
    ]);

    $result->assertSucceeded()
        ->assertOutputContains('Skipping [sequential-blog-pipeline]')
        ->assertOutputContains('Re-run with --force');

    // The original user-edited file must be left intact.
    expect(file_get_contents($this->skeletonPath('app/Ai/Swarms/SequentialBlogPipeline/BlogPipeline.php')))
        ->toBe("<?php\n// user-edited\n");
});

test('--force overwrites existing files', function () {
    $this->writeSkeletonFile(
        'app/Ai/Swarms/SequentialBlogPipeline/BlogPipeline.php',
        "<?php\n// user-edited\n",
    );

    $this->runInstaller('swarm:install:examples', [
        '--example' => ['sequential-blog-pipeline'],
        '--force' => true,
        '--no-interaction' => true,
    ])->assertSucceeded();

    $this->assertFileContains(
        'app/Ai/Swarms/SequentialBlogPipeline/BlogPipeline.php',
        'namespace App\Ai\Swarms\SequentialBlogPipeline;',
    );
});

test('it is idempotent when run twice without --force', function () {
    $this->runInstaller('swarm:install:examples', ['--all' => true, '--no-interaction' => true])
        ->assertSucceeded()
        ->twice()
        ->assertSecondRunIsNoOp();
});

test('--example rejects unknown names', function () {
    $this->assertInstallerFailsWith(
        'swarm:install:examples',
        ['--example' => ['no-such-example'], '--no-interaction' => true],
        'Unknown example(s): no-such-example',
    );
});

test('non-interactive mode requires --all or --example', function () {
    $this->assertInstallerFailsWith(
        'swarm:install:examples',
        ['--no-interaction' => true],
        'In non-interactive mode you must pass --all or one or more --example',
    );
});

test('--all and --example are mutually exclusive', function () {
    $this->assertInstallerFailsWith(
        'swarm:install:examples',
        ['--all' => true, '--example' => ['sequential-blog-pipeline'], '--no-interaction' => true],
        'Pass either --all or --example',
    );
});

test('it emits a runnable artisan hint for each installed example', function () {
    $this->runInstaller('swarm:install:examples', ['--all' => true, '--no-interaction' => true])
        ->assertSucceeded()
        ->assertOutputContains('php artisan swarm:example:blog-pipeline')
        ->assertOutputContains('php artisan swarm:example:research-fanout')
        ->assertOutputContains('php artisan swarm:example:approval-workflow');
});
