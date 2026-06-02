<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

afterEach(function () {
    ActiveRunContext::flush();
});

test('enter/exit manage a nested stack and current() reads the top frame', function () {
    expect(ActiveRunContext::current())->toBeNull();

    ActiveRunContext::enter('outer', 'OuterSwarm', RunContext::fake(['run_id' => 'outer']));
    expect(ActiveRunContext::current()?->runId)->toBe('outer');

    ActiveRunContext::enter('inner', 'InnerSwarm', RunContext::fake(['run_id' => 'inner']));
    expect(ActiveRunContext::current()?->runId)->toBe('inner');

    ActiveRunContext::exit();
    // The outer frame is restored, not lost.
    expect(ActiveRunContext::current()?->runId)->toBe('outer');

    ActiveRunContext::exit();
    expect(ActiveRunContext::current())->toBeNull();
});

test('flush() discards every frame for worker-reset isolation', function () {
    ActiveRunContext::enter('a', 'SwarmA', RunContext::fake(['run_id' => 'a']));
    ActiveRunContext::enter('b', 'SwarmB', RunContext::fake(['run_id' => 'b']));

    ActiveRunContext::flush();

    expect(ActiveRunContext::current())->toBeNull();
});
