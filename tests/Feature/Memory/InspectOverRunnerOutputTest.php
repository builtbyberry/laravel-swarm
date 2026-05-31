<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Artisan;

/**
 * End-to-end coverage for the gap the rest of SwarmMemoryInspectCommandTest
 * leaves open: those tests seed `swarm_memory_snapshots` rows by hand. This
 * one drives a real runner under the database persistence stack, lets the
 * runner write its own snapshots, and then inspects them through the command
 * exactly as an operator would — proving the inspect path works against rows a
 * runner actually produced, including the persisted row timestamps surfaced by
 * `recorded_at`.
 */
beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('inspects snapshots a real sequential run actually persisted', function () {
    $runId = 'run-e2e-inspect';

    FakeSequentialSwarm::make()->run(RunContext::from('original-task', $runId));

    $exit = Artisan::call('swarm:memory:inspect', [
        'run_id' => $runId,
        '--format' => 'json',
    ]);

    expect($exit)->toBe(0);

    $output = json_decode(Artisan::output(), true);
    expect($output['ok'])->toBeTrue();
    expect($output['run_id'])->toBe($runId);
    // FakeSequentialSwarm runs three agents; each snapshots once before
    // invocation, so the inspector should surface three steps in order.
    expect($output['snapshot_count'])->toBe(3);
    expect(array_column($output['snapshots'], 'step_index'))->toBe([0, 1, 2]);
    // The runner-written rows carry real timestamps — recorded_at must be
    // populated, not the null the projection used to hardcode.
    expect($output['snapshots'][0]['recorded_at'])->not->toBeNull();
});
