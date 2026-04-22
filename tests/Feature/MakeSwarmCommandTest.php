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
