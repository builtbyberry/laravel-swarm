<?php

use Illuminate\Support\Str;

test('durable documentation includes production recovery and worker guidance', function () {
    $contents = file_get_contents(__DIR__.'/../../docs/durable-execution.md');

    expect($contents)->toContain("Schedule::command('swarm:recover')->everyFiveMinutes()->withoutOverlapping(60);")
        ->and($contents)->toContain("Schedule::command('swarm:prune')->daily();")
        ->and($contents)->toContain('dedicated queue')
        ->and($contents)->toContain('retry_after')
        ->and($contents)->toContain('swarm.durable.step_timeout')
        ->and($contents)->toContain('sequential durable swarms')
        ->and($contents)->toContain('Durable execution supports sequential, parallel, and hierarchical swarms')
        ->and($contents)->toContain('durable branch jobs')
        ->and($contents)->toContain('swarm.durable.parallel.failure_policy')
        ->and($contents)->toContain('## Operational State')
        ->and($contents)->toContain('durable runtime record')
        ->and($contents)->toContain('inspection-safe projection')
        ->and($contents)->toContain('durable node-output rows');
});

test('every shipped relay and recovery schedule example carries an explicit finite mutex', function () {
    $paths = [
        __DIR__.'/../../README.md',
        __DIR__.'/../../config/swarm.php',
        __DIR__.'/../../src/Commands/SwarmHealthCommand.php',
        __DIR__.'/../../src/Commands/SwarmRecoverCommand.php',
        __DIR__.'/../../src/Commands/SwarmRelayCommand.php',
        ...(glob(__DIR__.'/../../docs/*.md') ?: []),
    ];
    $unprotected = [];
    $minuteRecovery = [];
    $seen = 0;

    foreach ($paths as $path) {
        foreach (file($path) ?: [] as $index => $line) {
            if (! preg_match("/Schedule::command\\(['\"]swarm:(relay|recover)['\"]\\)/", $line)) {
                continue;
            }

            $seen++;
            $location = str_replace(dirname(__DIR__, 2).'/', '', $path).':'.($index + 1);

            if (! str_contains($line, 'withoutOverlapping(')) {
                $unprotected[] = $location;
            }

            if (str_contains($line, 'swarm:recover') && str_contains($line, 'everyMinute()')) {
                $minuteRecovery[] = $location;
            }
        }
    }

    expect($seen)->toBeGreaterThan(0)
        ->and($unprotected)->toBe([], 'Bare schedule examples: '.implode(', ', $unprotected))
        ->and($minuteRecovery)->toBe([], 'Non-canonical one-minute recovery examples: '.implode(', ', $minuteRecovery));
});

test('persistence documentation names durable runtime inspection access', function () {
    $contents = file_get_contents(__DIR__.'/../../docs/persistence-and-history.md');

    expect($contents)->toContain('SwarmHistory` remains the stable history surface')
        ->and($contents)->toContain('app(DurableRunStore::class)->find($runId)')
        ->and($contents)->toContain('Active route plans can contain worker prompts')
        ->and($contents)->toContain('durable runtime failure metadata');
});

test('streaming documentation covers topology replay capture and limits', function () {
    $contents = file_get_contents(__DIR__.'/../../docs/streaming.md');

    expect($contents)->toContain('Sequential, Static-Hierarchical, and Hierarchical')
        ->and($contents)->toContain('bounded loops')
        ->and($contents)->toContain('storeForReplay')
        ->and($contents)->toContain('SwarmHistory::replay')
        ->and($contents)->toContain('swarm_stream_error')
        ->and($contents)->toContain('persistence-and-history.md#payload-limits')
        ->and($contents)->toContain('swarm.capture');
});

test('maintenance documentation includes the enterprise pilot posture', function () {
    $contents = Str::lower(file_get_contents(__DIR__.'/../../docs/maintenance.md'));

    expect($contents)->toContain('schedule `swarm:prune`')
        ->and($contents)->toContain('schedule `swarm:recover`')
        ->and($contents)->toContain('dedicated queue')
        ->and($contents)->toContain('lower-sensitivity data')
        ->and($contents)->toContain('conservative capture settings')
        ->and($contents)->toContain('--dry-run')
        ->and($contents)->toContain('swarm.retention.prevent_prune')
        ->and($contents)->toContain('swarm_prevent_prune');
});
