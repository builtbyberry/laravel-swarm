<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Clean every path a scaffold may have written into the testbench app.
 */
function cleanBlueprintScaffold(string $name): void
{
    File::deleteDirectory(app_path("Ai/Swarms/{$name}"));
    File::deleteDirectory(app_path("Ai/Agents/{$name}"));
    File::delete(app_path("Console/Commands/{$name}Command.php"));
}

afterEach(function () {
    foreach (['SupportTriage', 'ForcedName', 'SkipCommand'] as $name) {
        cleanBlueprintScaffold($name);
    }
    // Sweep anything install:examples landed during the regression test.
    foreach (glob(app_path('Ai/Swarms/*'), GLOB_ONLYDIR) ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
    foreach (glob(app_path('Ai/Agents/*'), GLOB_ONLYDIR) ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
    foreach (glob(app_path('Console/Commands/*.php')) ?: [] as $file) {
        File::delete($file);
    }
});

test('make:swarm:blueprint scaffolds a renamed swarm, agents, and command', function () {
    $exit = Artisan::call('make:swarm:blueprint', ['name' => 'SupportTriage', '--template' => 'research',
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(0);

    $swarm = app_path('Ai/Swarms/SupportTriage/SupportTriage.php');
    $agent = app_path('Ai/Agents/SupportTriage/MarketScout.php');
    $command = app_path('Console/Commands/SupportTriageCommand.php');

    expect(File::exists($swarm))->toBeTrue()
        ->and(File::exists($agent))->toBeTrue()
        ->and(File::exists($command))->toBeTrue();

    $swarmContents = File::get($swarm);
    expect($swarmContents)
        ->toContain('namespace App\Ai\Swarms\SupportTriage;')
        ->toContain('class SupportTriage implements Swarm')
        ->toContain('use App\Ai\Agents\SupportTriage\MarketScout;')
        ->not->toContain('ResearchFanout')
        ->not->toContain('ParallelResearchFanout')
        ->not->toContain('{{ rootNamespace }}');

    // Agent class names are deliberately preserved; only the namespace segment is renamed.
    expect(File::get($agent))
        ->toContain('namespace App\Ai\Agents\SupportTriage;')
        ->toContain('class MarketScout');
});

test('make:swarm:blueprint renames the console command and its signature', function () {
    Artisan::call('make:swarm:blueprint', ['name' => 'SupportTriage', '--template' => 'research',
        '--no-interaction' => true,
    ]);

    $command = File::get(app_path('Console/Commands/SupportTriageCommand.php'));

    expect($command)
        ->toContain('class SupportTriageCommand extends Command')
        ->toContain("#[AsCommand(name: 'swarm:run:support-triage')]")
        ->toContain('swarm:run:support-triage {topic')
        ->toContain('SupportTriage::make()')
        ->not->toContain('SwarmExampleResearchFanoutCommand')
        ->not->toContain('swarm:example:research-fanout');
});

test('--without-command skips the runnable command but still scaffolds the swarm', function () {
    $exit = Artisan::call('make:swarm:blueprint', [
        'name' => 'SkipCommand',
        '--template' => 'research',
        '--without-command' => true,
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(File::exists(app_path('Ai/Swarms/SkipCommand/SkipCommand.php')))->toBeTrue()
        ->and(File::exists(app_path('Console/Commands/SkipCommandCommand.php')))->toBeFalse();
});

test('make:swarm:blueprint refuses to overwrite existing files without --force', function () {
    Artisan::call('make:swarm:blueprint', ['name' => 'ForcedName', '--template' => 'research',
        '--no-interaction' => true,
    ]);

    $exit = Artisan::call('make:swarm:blueprint', ['name' => 'ForcedName', '--template' => 'research',
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Refusing to overwrite');
});

test('--force overwrites an existing scaffold', function () {
    Artisan::call('make:swarm:blueprint', ['name' => 'ForcedName', '--template' => 'research',
        '--no-interaction' => true,
    ]);

    $exit = Artisan::call('make:swarm:blueprint', [
        'name' => 'ForcedName',
        '--template' => 'research',
        '--force' => true,
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(0);
});

test('non-interactive mode requires --template', function () {
    $exit = Artisan::call('make:swarm:blueprint', ['name' => 'SupportTriage',
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())
        ->toContain('you must pass --template')
        ->toContain('research');
});

test('an unknown --template is rejected with the available list', function () {
    $exit = Artisan::call('make:swarm:blueprint', ['name' => 'SupportTriage', '--template' => 'nope',
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())
        ->toContain('Unknown blueprint [nope]')
        ->toContain('research');
});

test('an invalid swarm name is rejected', function () {
    $exit = Artisan::call('make:swarm:blueprint', ['name' => '123', '--template' => 'research',
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('not a valid swarm name');
});

test('every discoverable blueprint scaffolds cleanly', function () {
    // Guards each shipped blueprint end-to-end, not just the research fixture.
    foreach (['pipeline', 'research', 'approval'] as $slug) {
        $exit = Artisan::call('make:swarm:blueprint', ['name' => 'SupportTriage', '--template' => $slug,
            '--no-interaction' => true,
        ]);

        expect($exit)->toBe(0, "blueprint [{$slug}] failed to scaffold")
            ->and(File::exists(app_path('Ai/Swarms/SupportTriage/SupportTriage.php')))->toBeTrue();

        cleanBlueprintScaffold('SupportTriage');
    }
});

test('swarm:install:examples never lands the package-side blueprint.json manifest', function () {
    // Byte-identical invariant: the shared-corpus refactor must not leak the
    // new manifest into the host app tree — anywhere under app/.
    Artisan::call('swarm:install:examples', ['--all' => true,
        '--no-interaction' => true,
    ]);

    $leaked = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => $file->getFilename() === 'blueprint.json')
        ->map(fn ($file) => $file->getPathname())
        ->values()
        ->all();

    expect($leaked)->toBe([]);
});

test('a reserved swarm name is rejected before generating uncompilable code', function () {
    // `class Swarm implements Swarm` would collide with the imported contract.
    $exit = Artisan::call('make:swarm:blueprint', ['name' => 'Swarm', '--template' => 'research',
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('reserved name');
});
