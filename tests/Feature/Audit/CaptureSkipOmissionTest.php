<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingAuditCapturePolicy;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

/**
 * Bind a custom audit CapturePolicy and rebuild the capture-dependent
 * singletons so the running swarm picks up the new decisions.
 */
function bindCaptureSkipPolicy(SkippingAuditCapturePolicy $policy): void
{
    app()->instance(CapturePolicy::class, $policy);

    foreach ([
        SwarmCapture::class,
        ContextStore::class,
        RunHistoryStore::class,
        DatabaseContextStore::class,
        DatabaseRunHistoryStore::class,
        SwarmRunner::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

test('Skip on outputs omits output from history and events while the live response stays full', function (): void {
    Event::fake();

    bindCaptureSkipPolicy(new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Full,
        outputs: CaptureDecision::Skip,
        artifacts: CaptureDecision::Full,
        activeContext: CaptureDecision::Full,
    ));

    $response = FakeSequentialSwarm::make()->run('skip-outputs-task');
    $runId = $response->metadata['run_id'];
    $history = app(RunHistoryStore::class)->find($runId);

    // The live response handed back to the caller is never trimmed.
    expect($response->output)->toBe('editor-out');

    // Persisted history omits the output column and per-step output key.
    expect($history['output'])->toBeNull();
    expect($history['steps'][0])->not->toHaveKey('output');
    expect($history['steps'][0])->toHaveKey('input');

    Event::assertDispatched(SwarmCompleted::class, fn (SwarmCompleted $event): bool => $event->output === null);
    Event::assertDispatched(SwarmStepCompleted::class, fn (SwarmStepCompleted $event): bool => $event->output === null && $event->input !== null);
});

test('Skip on inputs omits input from history context, steps, and events', function (): void {
    Event::fake();

    bindCaptureSkipPolicy(new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Skip,
        outputs: CaptureDecision::Full,
        artifacts: CaptureDecision::Full,
        activeContext: CaptureDecision::Full,
    ));

    $response = FakeSequentialSwarm::make()->run('skip-inputs-task');
    $runId = $response->metadata['run_id'];
    $history = app(RunHistoryStore::class)->find($runId);

    expect($history['context'])->not->toHaveKey('input');
    expect($history['context']['data'])->not->toHaveKey('input');
    expect($history['steps'][0])->not->toHaveKey('input');
    expect($history['steps'][0])->toHaveKey('output');

    Event::assertDispatched(SwarmStarted::class, fn (SwarmStarted $event): bool => $event->input === null);
    Event::assertDispatched(SwarmStepStarted::class, fn (SwarmStepStarted $event): bool => $event->input === null);
});

test('Skip omission persists NULL columns on the database driver', function (): void {
    config()->set('swarm.persistence.driver', 'database');

    bindCaptureSkipPolicy(new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Skip,
        outputs: CaptureDecision::Skip,
        artifacts: CaptureDecision::Skip,
        activeContext: CaptureDecision::Skip,
    ));

    $response = FakeSequentialSwarm::make()->run('skip-db-task');
    $runId = $response->metadata['run_id'];

    $stepRow = DB::table('swarm_run_steps')->where('run_id', $runId)->orderBy('step_index')->first();
    expect($stepRow->input)->toBeNull();
    expect($stepRow->output)->toBeNull();

    $contextRow = DB::table('swarm_contexts')->where('run_id', $runId)->first();
    expect($contextRow->input)->toBeNull();

    $historyRow = DB::table('swarm_run_histories')->where('run_id', $runId)->first();
    expect($historyRow->output)->toBeNull();

    // Reconstructed history keeps the keys absent rather than null-valued.
    $history = app(RunHistoryStore::class)->find($runId);
    expect($history['steps'][0])->not->toHaveKey('input');
    expect($history['steps'][0])->not->toHaveKey('output');
});

test('boolean capture path still redacts (never omits) when outputs are disabled', function (): void {
    config()->set('swarm.capture.outputs', false);

    $response = FakeSequentialSwarm::make()->run('boolean-frozen-task');
    $history = app(RunHistoryStore::class)->find($response->metadata['run_id']);

    // Frozen contract: the boolean path Redacts, leaving the key present.
    expect($history['steps'][0])->toHaveKey('output');
    expect($history['steps'][0]['output'])->toBe(SwarmCapture::REDACTED);
    expect($history['output'])->toBe(SwarmCapture::REDACTED);
});

test('Skip on failures omits the error message but keeps the class', function (): void {
    bindCaptureSkipPolicy(new SkippingAuditCapturePolicy(
        inputs: CaptureDecision::Skip,
        outputs: CaptureDecision::Skip,
    ));

    try {
        FailingQueuedSwarm::make()->run('skip-failure-task');
        $this->fail('Expected the swarm to fail.');
    } catch (RuntimeException) {
        //
    }

    $history = app(RunHistoryStore::class)->query(status: 'failed', limit: 1)[0];

    expect($history['error'])->not->toHaveKey('message');
    expect($history['error']['class'])->toBe(RuntimeException::class);
});
