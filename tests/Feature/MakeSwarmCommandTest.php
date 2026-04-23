<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

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
