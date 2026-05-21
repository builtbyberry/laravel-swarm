<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

test('make swarm still works as a deprecated alias that prints a migration hint', function () {
    $path = app_path('Ai/Swarms/DeprecatedAliasSwarm.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    $this->artisan('make:swarm', ['name' => 'DeprecatedAliasSwarm', '--topology' => 'sequential'])
        ->expectsOutputToContain('make:swarm is deprecated')
        ->assertExitCode(0);

    // Old command still produced the file under the original path.

    expect(File::exists($path))->toBeTrue();

    File::delete($path);
});

test('make swarm generates a swarm class in app ai swarms', function () {
    $path = app_path('Ai/Swarms/ContentPipeline.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm', ['name' => 'ContentPipeline']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)->toContain('namespace App\Ai\Swarms;')
        ->and($contents)->toContain('class ContentPipeline implements Swarm')
        ->and($contents)->toContain('use Runnable;')
        ->and($contents)->toContain('BuiltByBerry\LaravelSwarm\Contracts\Swarm');

    File::delete($path);
});

test('make swarm uses the published custom stub when present', function () {
    $path = app_path('Ai/Swarms/CustomStubSwarm.php');
    $stubPath = base_path('stubs/swarm.stub');
    $stubDirectory = dirname($stubPath);
    $original = File::exists($stubPath) ? File::get($stubPath) : null;

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));
    File::ensureDirectoryExists($stubDirectory);
    File::put($stubPath, <<<'STUB'
<?php

namespace {{ namespace }};

class {{ class }}
{
    public const CUSTOM_STUB = true;
}
STUB);

    Artisan::call('make:swarm', ['name' => 'CustomStubSwarm']);

    expect(File::get($path))->toContain('public const CUSTOM_STUB = true;');

    File::delete($path);

    if ($original === null) {
        File::delete($stubPath);
    } else {
        File::put($stubPath, $original);
    }
});

test('make swarm generates a static-hierarchical stub when --topology=static-hierarchical is passed', function () {
    $path = app_path('Ai/Swarms/StaticRoutingSwarm.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm', ['name' => 'StaticRoutingSwarm', '--topology' => 'static-hierarchical']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('namespace App\Ai\Swarms;')
        ->toContain('class StaticRoutingSwarm implements HasRoutePlan, Swarm')
        ->toContain('use Runnable;')
        ->toContain('StaticHierarchical')
        ->toContain('HasRoutePlan')
        ->toContain('public function plan(): array');

    File::delete($path);
});

test('make swarm rejects an unknown --topology value with an error', function () {
    $this->artisan('make:swarm', ['name' => 'Foo', '--topology' => 'foobar'])
        ->expectsOutputToContain('Invalid topology [foobar]')
        ->assertExitCode(1);
});

test('make swarm defaults to sequential stub when no topology option is provided', function () {
    $path = app_path('Ai/Swarms/DefaultTopologySwarm.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm', ['name' => 'DefaultTopologySwarm']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('class DefaultTopologySwarm implements Swarm')
        ->toContain('TopologyEnum::Sequential')
        ->not->toContain('HasRoutePlan')
        ->not->toContain('plan()');

    File::delete($path);
});

test('make swarm generates a parallel stub when --topology=parallel is passed', function () {
    $path = app_path('Ai/Swarms/ResearchSwarm.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm', ['name' => 'ResearchSwarm', '--topology' => 'parallel']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('namespace App\Ai\Swarms;')
        ->toContain('class ResearchSwarm implements Swarm')
        ->toContain('use Runnable;')
        ->toContain('TopologyEnum::Parallel')
        ->not->toContain('HasRoutePlan')
        ->not->toContain('plan()');

    File::delete($path);
});

test('make swarm generates a hierarchical stub when --topology=hierarchical is passed', function () {
    $path = app_path('Ai/Swarms/RoutingSwarm.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm', ['name' => 'RoutingSwarm', '--topology' => 'hierarchical']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('namespace App\Ai\Swarms;')
        ->toContain('class RoutingSwarm implements Swarm')
        ->toContain('use Runnable;')
        ->toContain('TopologyEnum::Hierarchical')
        ->toContain('YourCoordinatorAgent')
        ->not->toContain('HasRoutePlan')
        ->not->toContain('plan()');

    File::delete($path);
});

test('make swarm defaults to sequential when run non-interactively without --topology', function () {
    $path = app_path('Ai/Swarms/NonInteractiveSwarm.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    // Artisan::call() runs non-interactively — should silently default to sequential
    Artisan::call('make:swarm', ['name' => 'NonInteractiveSwarm']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('TopologyEnum::Sequential')
        ->not->toContain('HasRoutePlan');

    File::delete($path);
});

test('make swarm prompts for topology when run interactively without --topology', function () {
    $path = app_path('Ai/Swarms/PromptedSwarm.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    $this->artisan('make:swarm', ['name' => 'PromptedSwarm'])
        ->expectsChoice('Which topology?', 'parallel', ['sequential', 'parallel', 'hierarchical', 'static-hierarchical'])
        ->assertExitCode(0);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)->toContain('TopologyEnum::Parallel');

    File::delete($path);
});
