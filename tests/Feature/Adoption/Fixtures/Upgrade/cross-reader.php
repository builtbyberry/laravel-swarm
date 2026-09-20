<?php

declare(strict_types=1);

// Execute twice in separate processes: candidate export, then pinned v0.25 read.
require getcwd().'/vendor/autoload.php';

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\ToolResult;

$harness = new class('fixture') extends TestCase
{
    public function boot(): void
    {
        $this->setUp();
    }
};
$harness->boot();
Carbon::setTestNow('2026-09-18 00:00:00 UTC');
config()->set('app.key', 'base64:'.base64_encode(str_repeat('u', 32)));
app()->forgetInstance('encrypter');
config()->set('swarm.persistence.driver', 'database');
config()->set('swarm.persistence.encrypt_at_rest', true);
config()->set('swarm.durable.queue.connection', 'durable-test');
$manager = app(DurableSwarmManager::class);
$store = app(DurableRunStore::class);
$mode = $argv[1] ?? '';
try {
    if ($mode === 'export') {
        foreach (['pending', 'waiting', 'running'] as $status) {
            $response = FakeSequentialSwarm::make()->dispatchDurable(new RunContext('candidate-'.$status, 'candidate-input-'.$status, ['retained' => 42], ['tenant' => 'candidate']));
            if ($status === 'waiting') {
                $manager->wait($response->runId, 'approval');
            }
            if ($status === 'running') {
                $token = $store->acquireLease($response->runId, 0, 30);
                $store->markRunning($response->runId, $token, 0);
            }
            unset($response);
        }
        foreach ([[true, false], [false, true], [true, true]] as $index => [$denied, $failed]) {
            app(StreamEventStore::class)->record('candidate-pending', new SwarmToolResult('candidate-'.$index, 'candidate-pending', 0, 'Agent', new ToolResult('call-'.$index, 'tool', [], 'not executed', denied: $denied, failed: $failed), false, 'not executed', 1789689600), 3600);
        }
        $tables = [];
        foreach (DB::connection()->getSchemaBuilder()->getTables() as $table) {
            if (str_starts_with($table['name'], 'swarm_')) {
                $rows = DB::table($table['name'])->get()->map(fn ($row) => (array) $row)->all();
                if ($rows !== []) {
                    $tables[$table['name']] = $rows;
                }
            }
        }
        file_put_contents($argv[2], json_encode($tables, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        echo "Candidate rows exported\n";
    } elseif ($mode === 'read') {
        $tables = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
        DB::connection()->getSchemaBuilder()->disableForeignKeyConstraints();
        foreach ($tables as $table => $rows) {
            DB::table($table)->insert($rows);
        }
        DB::connection()->getSchemaBuilder()->enableForeignKeyConstraints();
        foreach (['pending', 'waiting', 'running'] as $status) {
            $id = 'candidate-'.$status;
            if ($store->find($id)['status'] !== $status || app(ContextStore::class)->find($id)['input'] !== 'candidate-input-'.$status || app(ContextStore::class)->find($id)['data']['retained'] !== 42 || app(RunHistoryStore::class)->find($id) === null) {
                throw new RuntimeException('Historical active-row read failed: '.$status);
            }
        }
        if ($store->waits('candidate-waiting')[0]['name'] !== 'approval') {
            throw new RuntimeException('Historical wait read failed.');
        }
        $events = iterator_to_array(app(StreamEventStore::class)->events('candidate-pending'));
        if (count($events) !== 3) {
            throw new RuntimeException('Historical replay count failed.');
        }
        foreach ($events as $event) {
            if ($event->successful !== false || $event->toolResult->denied !== false || property_exists($event->toolResult, 'failed')) {
                throw new RuntimeException('Expected pinned old-reader correction loss was not observed.');
            }
        }
        echo "PASS: previous readers parse three candidate active states, sealed contexts, history, wait and three replay outcomes.\n";
        echo "DOWNGRADE BLOCKED: old AI0.10.3/Swarm0.25 loses denied and failed semantics. Retain the correction-preserving reader; never remove evidence.\n";
    } else {
        throw new RuntimeException('Expected export or read.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}
