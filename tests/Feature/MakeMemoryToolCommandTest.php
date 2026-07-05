<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\MakeMemoryToolCommand;
use BuiltByBerry\LaravelSwarm\Tools\Recall;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Console\Kernel as FoundationKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Test double that reports the vector companion as installed, so the `--vector`
 * generation path can be exercised without the optional companion package being
 * a dependency of this repo.
 */
final class MakeMemoryToolCommandWithVector extends MakeMemoryToolCommand
{
    protected function vectorCompanionInstalled(): bool
    {
        return true;
    }
}

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

test('make:memory-tool --vector scaffolds a compiling vector tool when the companion is present', function () {
    // The vector companion is not a dependency, so the real command always
    // refuses (covered by the absent-companion test above). Swap in a double
    // that reports it present and register it under the same signature so the
    // harness resolves it for this test — the same kernel dance the installer
    // suite uses in registerInstallerCommand().
    $kernel = app(ConsoleKernel::class);
    assert($kernel instanceof FoundationKernel);
    // Force Artisan to initialize before attaching the replacement, so the
    // command binds to the live application call() dispatches to.
    $kernel->call('list', [], new BufferedOutput);
    $kernel->registerCommand(app(MakeMemoryToolCommandWithVector::class));

    $path = app_path('Ai/Tools/VectorRecallShape.php');

    File::ensureDirectoryExists(dirname($path));

    Artisan::call('make:memory-tool', ['name' => 'VectorRecallShape', '--vector' => true]);

    expect(File::exists($path))->toBeTrue();

    $contents = File::get($path);

    // Every placeholder resolved + the vector stub's distinguishing shape. The
    // vector stub hardcodes `extends Recall` (no {{ baseTool }}), so --base is
    // irrelevant for this variant.
    expect($contents)
        ->toContain('namespace App\Ai\Tools;')
        ->toContain('class VectorRecallShape extends Recall')
        ->toContain("return 'vector_recall_shape';")
        ->toContain('protected MemoryScope $defaultScope = MemoryScope::Run;')
        ->toContain('public function description(): Stringable|string')
        ->toContain('public function handle(Request $request): string')
        ->toContain('protected function semanticRecall(string $query, MemoryScope $scope): string')
        ->toContain('protected function resolveScope(string $scope): ?MemoryScope')
        ->toContain('protected function agent(): ?Agent')
        ->not->toContain('{{');

    // It must actually compile and be a real Recall subclass — a broken
    // placeholder or stray syntax in the 128-line vector stub fails here.
    // Unique class name, required exactly once (a same-named redeclare fatals).
    require_once $path;

    expect(class_exists('App\Ai\Tools\VectorRecallShape'))->toBeTrue();
    expect((new ReflectionClass('App\Ai\Tools\VectorRecallShape'))->isSubclassOf(Recall::class))->toBeTrue();
});
