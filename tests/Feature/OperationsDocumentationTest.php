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
        ->and($contents)->toContain('Durable execution supports sequential, parallel, and hierarchical swarms')
        ->and($contents)->toContain('durable branch jobs')
        ->and($contents)->toContain('swarm.durable.parallel.failure_policy')
        ->and($contents)->toContain('## Operational State')
        ->and($contents)->toContain('durable runtime record')
        ->and($contents)->toContain('inspection-safe projection')
        ->and($contents)->toContain('durable node-output rows');
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

    expect($contents)->toContain('Sequential Only')
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
        ->and($contents)->toContain('conservative capture settings');
});
