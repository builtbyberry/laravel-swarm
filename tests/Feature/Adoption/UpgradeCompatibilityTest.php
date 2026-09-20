<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseStreamEventStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Unit\Streaming\Fixtures\LegacyToolResult;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\ToolResult;

covers(InvokeSwarm::class, BroadcastSwarm::class, AdvanceDurableSwarm::class, AdvanceDurableBranch::class, ResumeQueuedHierarchicalSwarm::class, DatabaseDurableRunStore::class, DatabaseStreamEventStore::class, SwarmToolResult::class);

function upgradeFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/Upgrade/v025.json'), true, flags: JSON_THROW_ON_ERROR);
}

function upgradeJob(string $name): object
{
    return unserialize(base64_decode(upgradeFixture()['jobs'][$name], true), ['allowed_classes' => [
        InvokeSwarm::class, BroadcastSwarm::class, AdvanceDurableSwarm::class,
        AdvanceDurableBranch::class, ResumeQueuedHierarchicalSwarm::class,
        Channel::class,
    ]]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-18 00:01:00 UTC');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('u', 32)));
    app()->forgetInstance('encrypter');
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.recovery.grace_seconds', 0);
    config()->set('queue.default', 'durable-test');
    config()->set('broadcasting.default', 'null');
    Http::preventStrayRequests();
    $schema = DB::connection()->getSchemaBuilder();
    $schema->disableForeignKeyConstraints();
    try {
        foreach (upgradeFixture()['tables'] as $table => $rows) {
            DB::table($table)->insert($rows);
        }
    } finally {
        $schema->enableForeignKeyConstraints();
    }
    FakeResearcher::fake(['researched']);
    FakeWriter::fake(['written']);
    FakeEditor::fake(['edited']);
});

afterEach(fn () => Carbon::setTestNow());

it('executes frozen v025 pending waiting and expired running jobs with sealed context', function (string $status) {
    $id = 'upgrade-'.$status;
    $manager = app(DurableSwarmManager::class);
    expect($manager->find($id)['status'])->toBe($status)
        ->and(app(ContextStore::class)->find($id)['input'])->toBe('old-'.$status)
        ->and(DB::table('swarm_contexts')->where('run_id', $id)->value('input'))->toStartWith('sw0:');
    if ($status === 'waiting') {
        expect($manager->signal($id, 'approval', ['accepted' => true], 'upgrade-signal')->accepted)->toBeTrue();
    }
    if ($status === 'running') {
        $manager->recover(runId: $id);
        expect($manager->find($id)['recovery_count'])->toBe(1);
    }
    app()->call([upgradeJob($status), 'handle']);
    expect($manager->find($id)['next_step_index'])->toBe(1);
    app()->call([new AdvanceDurableSwarm($id, 1), 'handle']);
    app()->call([new AdvanceDurableSwarm($id, 2), 'handle']);
    $history = app(RunHistoryStore::class)->find($id);
    expect($manager->find($id)['status'])->toBe('completed')->and($history['output'])->toBe('edited')
        ->and($history['steps'])->toHaveCount(3)
        ->and($manager->find('upgrade-join')['status'])->toBe('waiting');
    FakeResearcher::assertPrompted(fn ($prompt) => $prompt->prompt === 'old-'.$status);
    Http::assertNothingSent();
})->with(['pending', 'waiting', 'running']);

it('executes frozen v025 queued and broadcast jobs through real Swarm runners', function (string $name, string $id) {
    $job = upgradeJob($name);
    expect($job->task['run_id'])->toBe($id)->and($job->enqueuedAtMs)->toBe(1789689600000);
    app()->call([$job, 'handle']);
    expect(app(RunHistoryStore::class)->find($id)['status'])->toBe('completed')
        ->and(app(RunHistoryStore::class)->find($id)['output'])->toBe('edited');
    FakeResearcher::assertPrompted(fn ($prompt) => $prompt->prompt === ($name === 'invoke' ? 'old-queue' : 'old-broadcast'));
    Http::assertNothingSent();
})->with([['invoke', 'upgrade-queue'], ['broadcast', 'upgrade-broadcast']]);

it('executes frozen v025 branch and resume jobs against the historical coordinated join', function () {
    app()->call([upgradeJob('branch'), 'handle']);
    $store = app(DurableRunStore::class);
    expect($store->branchesFor('upgrade-join')[0]['status'])->toBe('completed');
    app()->call([upgradeJob('resume'), 'handle']);
    expect($store->find('upgrade-join')['status'])->toBe('completed')
        ->and(app(RunHistoryStore::class)->find('upgrade-join')['output'])->toBe('written');
    FakeWriter::assertPrompted(fn ($prompt) => $prompt->prompt === 'old-branch');
    Http::assertNothingSent();
});

it('reads candidate persisted corrections and proves the pinned historical reader loses their semantics', function (bool $denied, bool $failed) {
    $event = new SwarmToolResult('candidate-event', 'upgrade-pending', 0, 'Agent', new ToolResult('call', 'tool', [], 'not executed', denied: $denied, failed: $failed), false, 'not executed', 1789689600);
    app(StreamEventStore::class)->record('upgrade-pending', $event, 3600);
    $raw = DB::table('swarm_stream_events')->where('run_id', 'upgrade-pending')->first();
    $payload = json_decode($raw->payload, true, flags: JSON_THROW_ON_ERROR);
    $old = LegacyToolResult::fromArray($payload);
    $current = SwarmStreamEvent::fromArray($payload);
    expect($old->toolResult->successful())->toBeTrue()->and($old->successful)->toBeFalse()
        ->and($current->toolResult->successful())->toBeFalse()
        ->and($current->toolResult->denied)->toBe($denied)->and($current->toolResult->failed)->toBe($failed);
    expect(iterator_to_array(app(StreamEventStore::class)->events('upgrade-pending'))[0]->toArray())->toBe($current->toArray());
})->with([[true, false], [false, true], [true, true]]);
