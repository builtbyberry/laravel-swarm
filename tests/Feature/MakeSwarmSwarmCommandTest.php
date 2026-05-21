<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

afterEach(function () {
    foreach (glob(app_path('Ai/Swarms/*.php')) ?: [] as $file) {
        File::delete($file);
    }
});

test('make:swarm:swarm generates a sequential swarm by default', function () {
    $path = app_path('Ai/Swarms/DefaultSwarm.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm:swarm', ['name' => 'DefaultSwarm']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('namespace App\Ai\Swarms;')
        ->toContain('class DefaultSwarm implements Swarm')
        ->toContain('use Runnable;')
        ->toContain('TopologyEnum::Sequential')
        ->toContain('public function agents(): array')
        ->not->toContain('HasRoutePlan')
        ->not->toContain('public function plan(): array');
});

test('make:swarm:swarm generates a parallel swarm with --topology=parallel', function () {
    $path = app_path('Ai/Swarms/ParallelGenSwarm.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm:swarm', ['name' => 'ParallelGenSwarm', '--topology' => 'parallel']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('TopologyEnum::Parallel')
        ->toContain('class ParallelGenSwarm implements Swarm')
        ->not->toContain('HasRoutePlan');
});

test('make:swarm:swarm generates a hierarchical swarm with --topology=hierarchical', function () {
    $path = app_path('Ai/Swarms/HierGenSwarm.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm:swarm', ['name' => 'HierGenSwarm', '--topology' => 'hierarchical']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('TopologyEnum::Hierarchical')
        ->toContain('YourCoordinatorAgent')
        ->not->toContain('HasRoutePlan');
});

test('make:swarm:swarm generates a static-hierarchical swarm with --topology=static-hierarchical', function () {
    $path = app_path('Ai/Swarms/StaticHierGenSwarm.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm:swarm', ['name' => 'StaticHierGenSwarm', '--topology' => 'static-hierarchical']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('TopologyEnum::StaticHierarchical')
        ->toContain('class StaticHierGenSwarm implements HasRoutePlan, Swarm')
        ->toContain('public function plan(): array')
        ->toContain("'start_at' => 'finish'");
});

test('make:swarm:swarm rejects an unknown --topology value', function () {
    $this->artisan('make:swarm:swarm', ['name' => 'BadTopology', '--topology' => 'mesh'])
        ->expectsOutputToContain('Invalid topology [mesh]')
        ->assertExitCode(1);
});

test('make:swarm:swarm uses a published custom stub when present', function () {
    $path = app_path('Ai/Swarms/CustomStubGenSwarm.php');
    $stubPath = base_path('stubs/swarm.stub');
    $original = File::exists($stubPath) ? File::get($stubPath) : null;

    File::ensureDirectoryExists(dirname($path));
    File::ensureDirectoryExists(dirname($stubPath));
    File::put($stubPath, <<<'STUB'
<?php

namespace {{ namespace }};

class {{ class }}
{
    public const CUSTOM_SWARM_STUB = true;
}
STUB);

    try {
        Artisan::call('make:swarm:swarm', ['name' => 'CustomStubGenSwarm']);

        expect(File::get($path))->toContain('public const CUSTOM_SWARM_STUB = true;');
    } finally {
        if ($original === null) {
            File::delete($stubPath);
        } else {
            File::put($stubPath, $original);
        }
    }
});

test('make:swarm:swarm defaults to sequential when run non-interactively without --topology', function () {
    $path = app_path('Ai/Swarms/NonInteractiveGenSwarm.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm:swarm', ['name' => 'NonInteractiveGenSwarm']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)->toContain('TopologyEnum::Sequential');
});

// Interactive topology prompting is delegated to laravel/prompts `select()`
// and only fires under a real TTY. The non-interactive default path (which
// returns 'sequential') is covered by the "defaults to sequential when run
// non-interactively" test above.
