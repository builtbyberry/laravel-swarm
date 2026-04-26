<?php

use Illuminate\Support\Str;

test('durable documentation includes production recovery and worker guidance', function () {
    $contents = file_get_contents(__DIR__.'/../../docs/durable-execution.md');

    expect($contents)->toContain("Schedule::command('swarm:recover')->everyFiveMinutes();")
        ->and($contents)->toContain("Schedule::command('swarm:prune')->daily();")
        ->and($contents)->toContain('dedicated queue')
        ->and($contents)->toContain('retry_after')
        ->and($contents)->toContain('swarm.durable.step_timeout')
        ->and($contents)->toContain('sequential durable swarms')
        ->and($contents)->toContain('Durable execution supports sequential and hierarchical swarms')
        ->and($contents)->toContain('durable fan-out/fan-in is intentionally')
        ->and($contents)->toContain('## Operational State')
        ->and($contents)->toContain('durable runtime record')
        ->and($contents)->toContain('durable node-output rows');
});

test('maintenance documentation includes the enterprise pilot posture', function () {
    $contents = Str::lower(file_get_contents(__DIR__.'/../../docs/maintenance.md'));

    expect($contents)->toContain('schedule `swarm:prune`')
        ->and($contents)->toContain('schedule `swarm:recover`')
        ->and($contents)->toContain('dedicated queue')
        ->and($contents)->toContain('lower-sensitivity data')
        ->and($contents)->toContain('conservative capture settings');
});
