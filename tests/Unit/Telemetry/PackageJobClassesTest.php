<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryEventListener;

// The job.failed fallback only decodes and reports the classes it lists, so a
// package job subclass missing from the list fails silently.

it('lists every package job class the job.failed fallback reports on', function () {
    $bases = [
        InvokeSwarm::class,
        BroadcastSwarm::class,
        AdvanceDurableSwarm::class,
        AdvanceDurableBranch::class,
        ResumeQueuedHierarchicalSwarm::class,
    ];

    $packageJobs = collect(glob(dirname(__DIR__, 3).'/src/Jobs/*.php'))
        ->map(fn (string $path): string => 'BuiltByBerry\\LaravelSwarm\\Jobs\\'.basename($path, '.php'))
        ->filter(fn (string $class): bool => collect($bases)->contains(fn (string $base): bool => is_a($class, $base, true)))
        ->sort()
        ->values()
        ->all();

    $listed = collect(new ReflectionClassConstant(SwarmTelemetryEventListener::class, 'PACKAGE_JOB_CLASSES')->getValue())
        ->sort()
        ->values()
        ->all();

    expect($packageJobs)->toHaveCount(15)
        ->and($listed)->toBe($packageJobs);
});
