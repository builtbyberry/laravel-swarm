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

// ---------------------------------------------------------------------------
// Guided front door: single-agent scaffold + interactive single-vs-swarm prompt
// ---------------------------------------------------------------------------

test('make swarm scaffolds a single agent under app ai agents when --single is passed', function () {
    $path = app_path('Ai/Agents/Summarizer.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    // Artisan::call runs non-interactively — the flag must be honored without prompting.
    Artisan::call('make:swarm', ['name' => 'Summarizer', '--single' => true]);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('namespace App\Ai\Agents;')
        ->toContain('class Summarizer extends ScriptedAgent')
        // Demonstrates the new Swarm::agent() front door...
        ->toContain('Swarm::agent(new Summarizer)->prompt($task)')
        // ...and references the inline topology builders.
        ->toContain('Swarm::sequential([new Summarizer, new NextAgent])')
        ->toContain('Swarm::parallel(')
        ->toContain('Swarm::hierarchical(')
        // It is NOT a swarm class.
        ->not->toContain('implements Swarm');

    File::delete($path);
});

test('make swarm prompts for single-agent vs swarm when interactive and scaffolds the chosen agent', function () {
    $path = app_path('Ai/Agents/InteractiveAgent.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    $this->artisan('make:swarm', ['name' => 'InteractiveAgent'])
        ->expectsChoice('What would you like to scaffold?', 'single', [
            'single' => 'A single agent — run it instantly with Swarm::agent(), no swarm class',
            'swarm' => 'A multi-agent swarm — choose a topology',
        ])
        ->assertExitCode(0);

    expect(File::exists($path))->toBeTrue();
    expect(File::get($path))
        ->toContain('class InteractiveAgent extends ScriptedAgent')
        ->toContain('Swarm::agent(new InteractiveAgent)');

    File::delete($path);
});

test('make swarm interactive swarm choice falls through to the topology prompt', function () {
    $path = app_path('Ai/Swarms/InteractiveSwarm.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    $this->artisan('make:swarm', ['name' => 'InteractiveSwarm'])
        ->expectsChoice('What would you like to scaffold?', 'swarm', [
            'single' => 'A single agent — run it instantly with Swarm::agent(), no swarm class',
            'swarm' => 'A multi-agent swarm — choose a topology',
        ])
        ->expectsChoice('Which topology?', 'parallel', [
            'sequential' => 'Sequential — agents in order, each receives prior output',
            'parallel' => 'Parallel — agents concurrent, each gets the original task',
            'hierarchical' => 'Hierarchical — coordinator returns a DAG route plan at runtime',
            'static-hierarchical' => 'Static hierarchical — PHP-defined route plan, no coordinator call',
        ])
        ->assertExitCode(0);

    expect(File::exists($path))->toBeTrue();
    expect(File::get($path))
        ->toContain('class InteractiveSwarm implements Swarm')
        ->toContain('TopologyEnum::Parallel');

    File::delete($path);
});

test('make swarm --single takes precedence over --topology and still scaffolds an agent', function () {
    $path = app_path('Ai/Agents/PrecedenceAgent.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm', ['name' => 'PrecedenceAgent', '--single' => true, '--topology' => 'parallel']);

    expect(File::exists($path))->toBeTrue();
    expect(File::get($path))->toContain('class PrecedenceAgent extends ScriptedAgent');

    File::delete($path);
});

test('make swarm stays non-interactive-safe with --no-interaction and no flags', function () {
    $path = app_path('Ai/Swarms/CiSafeSwarm.php');

    if (File::exists($path)) {
        File::delete($path);
    }

    File::ensureDirectoryExists(dirname($path));

    // No expectsChoice registered: if the command tried to prompt, the run would fail.
    $this->artisan('make:swarm', ['name' => 'CiSafeSwarm', '--no-interaction' => true])
        ->assertExitCode(0);

    expect(File::exists($path))->toBeTrue();
    expect(File::get($path))
        ->toContain('class CiSafeSwarm implements Swarm')
        ->toContain('TopologyEnum::Sequential');

    File::delete($path);
});

test('make swarm honors a published custom single-agent stub', function () {
    $path = app_path('Ai/Agents/CustomStubAgent.php');
    $stubPath = base_path('stubs/swarm.single-agent.stub');
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
    public const CUSTOM_SINGLE_AGENT_STUB = true;
}
STUB);

    Artisan::call('make:swarm', ['name' => 'CustomStubAgent', '--single' => true]);

    expect(File::get($path))->toContain('public const CUSTOM_SINGLE_AGENT_STUB = true;');

    File::delete($path);

    if ($original === null) {
        File::delete($stubPath);
    } else {
        File::put($stubPath, $original);
    }
});

// The non-interactive default path (no flags, no TTY → sequential swarm class)
// is covered by the "defaults to sequential when run non-interactively" test
// above; the deprecation notice is asserted by the first test in this file.
