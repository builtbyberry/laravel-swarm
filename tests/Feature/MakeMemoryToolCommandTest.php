<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

afterEach(function () {
    foreach (glob(app_path('Ai/Tools/*.php')) ?: [] as $file) {
        File::delete($file);
    }
});

test('make:memory-tool generates a Recall-based tool in app/Ai/Tools by default', function () {
    $path = app_path('Ai/Tools/TenantRecall.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:memory-tool', ['name' => 'TenantRecall']);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    expect($contents)
        ->toContain('namespace App\Ai\Tools;')
        ->toContain('class TenantRecall extends Recall')
        ->toContain('use BuiltByBerry\LaravelSwarm\Tools\Recall;')
        ->toContain('protected MemoryScope $defaultScope = MemoryScope::Run;')
        ->toContain("return 'tenant_recall';")
        ->toContain('declare(strict_types=1);');
});

test('make:memory-tool --base=remember extends the Remember tool', function () {
    $path = app_path('Ai/Tools/DomainRemember.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:memory-tool', ['name' => 'DomainRemember', '--base' => 'remember']);

    $contents = File::get($path);

    expect($contents)
        ->toContain('class DomainRemember extends Remember')
        ->toContain('use BuiltByBerry\LaravelSwarm\Tools\Remember;');
});

test('make:memory-tool seeds the default scope from --scope', function (string $scope, string $case) {
    $path = app_path('Ai/Tools/ScopedTool.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:memory-tool', ['name' => 'ScopedTool', '--scope' => $scope]);

    expect(File::get($path))
        ->toContain('protected MemoryScope $defaultScope = MemoryScope::'.$case.';');
})->with([
    ['run', 'Run'],
    ['conversation', 'Conversation'],
    ['agent', 'Agent'],
    ['swarm', 'Swarm'],
]);

test('make:memory-tool rejects an unknown --scope value', function () {
    $this->artisan('make:memory-tool', ['name' => 'BadScope', '--scope' => 'galaxy'])
        ->expectsOutputToContain('Invalid scope [galaxy]')
        ->assertExitCode(1);

    expect(File::exists(app_path('Ai/Tools/BadScope.php')))->toBeFalse();
});

test('make:memory-tool rejects an unknown --base value', function () {
    $this->artisan('make:memory-tool', ['name' => 'BadBase', '--base' => 'forget'])
        ->expectsOutputToContain('Invalid base [forget]')
        ->assertExitCode(1);
});

test('make:memory-tool supports nested namespaces via slash-separated names', function () {
    $path = app_path('Ai/Tools/Memory/ScopedRecall.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:memory-tool', ['name' => 'Memory/ScopedRecall']);

    expect(File::exists($path))->toBeTrue();

    expect(File::get($path))
        ->toContain('namespace App\Ai\Tools\Memory;')
        ->toContain('class ScopedRecall extends Recall');

    File::delete($path);
    File::deleteDirectory(dirname($path));
});

test('make:memory-tool does not overwrite an existing tool without --force', function () {
    $path = app_path('Ai/Tools/Keeper.php');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php // original');

    Artisan::call('make:memory-tool', ['name' => 'Keeper']);

    expect(File::get($path))->toBe('<?php // original');
});

test('make:memory-tool --force overwrites an existing tool', function () {
    $path = app_path('Ai/Tools/Replaceable.php');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php // original');

    Artisan::call('make:memory-tool', ['name' => 'Replaceable', '--force' => true]);

    expect(File::get($path))
        ->not->toContain('// original')
        ->toContain('class Replaceable extends Recall');
});

test('make:memory-tool --vector errors when the companion package is absent', function () {
    // The vector companion is not a dependency of this package, so detection
    // must report it as missing and refuse to scaffold.
    expect(InstalledVersions::isInstalled('builtbyberry/laravel-swarm-memory-vector'))->toBeFalse();

    $this->artisan('make:memory-tool', ['name' => 'VectorRecall', '--vector' => true])
        ->expectsOutputToContain('builtbyberry/laravel-swarm-memory-vector')
        ->assertExitCode(1);

    expect(File::exists(app_path('Ai/Tools/VectorRecall.php')))->toBeFalse();
});

test('make:memory-tool uses a published custom stub when present', function () {
    $path = app_path('Ai/Tools/CustomStubTool.php');
    $stubPath = base_path('stubs/swarm.memory-tool.stub');
    $original = File::exists($stubPath) ? File::get($stubPath) : null;

    File::ensureDirectoryExists(dirname($path));
    File::ensureDirectoryExists(dirname($stubPath));
    File::put($stubPath, <<<'STUB'
<?php

namespace {{ namespace }};

class {{ class }}
{
    public const CUSTOM_TOOL_STUB = true;
}
STUB);

    try {
        Artisan::call('make:memory-tool', ['name' => 'CustomStubTool']);

        expect(File::get($path))->toContain('public const CUSTOM_TOOL_STUB = true;');
    } finally {
        if ($original === null) {
            File::delete($stubPath);
        } else {
            File::put($stubPath, $original);
        }
    }
});

test('make:memory-tool generated class shape matches the shipped Recall tool', function () {
    $path = app_path('Ai/Tools/ShapeParityTool.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:memory-tool', ['name' => 'ShapeParityTool']);

    $contents = File::get($path);

    // Parity with BuiltByBerry\LaravelSwarm\Tools\Recall:
    // - extends the shipped tool
    // - exposes name()
    // - overrides resolveScope() with the same signature the base declares
    // - overrides agent() returning ?Agent
    expect($contents)
        ->toMatch('/class ShapeParityTool extends Recall/')
        ->toMatch('/public function name\(\): string/')
        ->toMatch('/protected function resolveScope\(string \$scope\): \?MemoryScope/')
        ->toMatch('/protected function agent\(\): \?Agent/');
});
