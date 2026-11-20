<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

test('a stub published inside an isolated base path never reaches the shared skeleton', function () {
    $sharedBase = base_path();
    $sharedApp = app_path();
    $isolatedBase = null;

    withIsolatedBasePath(function () use ($sharedBase, $sharedApp, &$isolatedBase): void {
        $isolatedBase = base_path();

        expect($isolatedBase)->not->toBe($sharedBase)
            ->and(app_path())->toBe($isolatedBase.DIRECTORY_SEPARATOR.'app')
            ->and(app('path'))->toBe($isolatedBase.DIRECTORY_SEPARATOR.'app');

        File::put(base_path('stubs/swarm.stub'), <<<'STUB'
<?php

namespace {{ namespace }};

class {{ class }}
{
    public const ISOLATED_STUB = true;
}
STUB);

        Artisan::call('make:swarm:swarm', ['name' => 'IsolatedStubSwarm']);

        expect(File::get($isolatedBase.'/app/Ai/Swarms/IsolatedStubSwarm.php'))->toContain('public const ISOLATED_STUB = true;')
            ->and(File::exists($sharedBase.'/stubs/swarm.stub'))->toBeFalse()
            ->and(File::exists($sharedApp.'/Ai/Swarms/IsolatedStubSwarm.php'))->toBeFalse();
    });

    expect(base_path())->toBe($sharedBase)
        ->and(app_path())->toBe($sharedApp)
        ->and(app('path'))->toBe($sharedApp)
        ->and(File::exists($isolatedBase))->toBeFalse();
});

test('the shared base path is restored when the isolated body throws', function () {
    $sharedBase = base_path();
    $sharedApp = app_path();
    $isolatedBase = null;

    expect(function () use (&$isolatedBase): void {
        withIsolatedBasePath(function () use (&$isolatedBase): void {
            $isolatedBase = base_path();

            throw new RuntimeException('isolated body failed');
        });
    })->toThrow(RuntimeException::class, 'isolated body failed');

    expect($isolatedBase)->not->toBe($sharedBase)
        ->and(base_path())->toBe($sharedBase)
        ->and(app_path())->toBe($sharedApp)
        ->and(app('path'))->toBe($sharedApp)
        ->and(File::exists($isolatedBase))->toBeFalse();
});
