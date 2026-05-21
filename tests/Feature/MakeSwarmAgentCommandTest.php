<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

afterEach(function () {
    foreach (glob(app_path('Ai/Agents/*.php')) ?: [] as $file) {
        File::delete($file);
    }
});

test('make:swarm:agent generates an agent class in app/Ai/Agents', function () {
    $path = app_path('Ai/Agents/OutlineWriter.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm:agent', ['name' => 'OutlineWriter']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('namespace App\Ai\Agents;')
        ->toContain('class OutlineWriter extends ScriptedAgent')
        ->toContain('use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;')
        ->toContain('public function instructions(): string')
        ->toContain('protected function reply(string $prompt): string')
        ->toContain('// TODO: swap ScriptedAgent for a real Promptable agent')
        ->toContain('declare(strict_types=1);');
});

test('make:swarm:agent supports nested namespaces via slash-separated names', function () {
    $path = app_path('Ai/Agents/BlogPipeline/Drafter.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm:agent', ['name' => 'BlogPipeline/Drafter']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('namespace App\Ai\Agents\BlogPipeline;')
        ->toContain('class Drafter extends ScriptedAgent');

    File::delete($path);
    File::deleteDirectory(dirname($path));
});

test('make:swarm:agent uses a published custom stub when present', function () {
    $path = app_path('Ai/Agents/CustomStubAgent.php');
    $stubPath = base_path('stubs/swarm.agent.stub');
    $original = File::exists($stubPath) ? File::get($stubPath) : null;

    File::ensureDirectoryExists(dirname($path));
    File::ensureDirectoryExists(dirname($stubPath));
    File::put($stubPath, <<<'STUB'
<?php

namespace {{ namespace }};

class {{ class }}
{
    public const CUSTOM_AGENT_STUB = true;
}
STUB);

    try {
        Artisan::call('make:swarm:agent', ['name' => 'CustomStubAgent']);

        expect(File::get($path))->toContain('public const CUSTOM_AGENT_STUB = true;');
    } finally {
        if ($original === null) {
            File::delete($stubPath);
        } else {
            File::put($stubPath, $original);
        }
    }
});

test('make:swarm:agent generated class compiles and matches the starter-example shape', function () {
    $path = app_path('Ai/Agents/ShapeCheckAgent.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:swarm:agent', ['name' => 'ShapeCheckAgent']);

    $contents = File::get($path);

    // Visual consistency with stubs/examples/*/app/Ai/Agents/*.php:
    // - extends ScriptedAgent
    // - has instructions() returning a string
    // - has protected reply(string $prompt): string with a TODO comment
    expect($contents)
        ->toMatch('/class ShapeCheckAgent extends ScriptedAgent/')
        ->toMatch('/public function instructions\(\): string/')
        ->toMatch('/protected function reply\(string \$prompt\): string/')
        ->toContain('// TODO: swap ScriptedAgent for a real Promptable agent');
});
