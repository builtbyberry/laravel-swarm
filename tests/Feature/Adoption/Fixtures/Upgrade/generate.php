<?php

declare(strict_types=1);

// Run with the pinned previous checkout as cwd; see README.md in this directory.
require getcwd().'/vendor/autoload.php';

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\TestCase;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
config()->set('queue.default', 'durable-test');
Http::preventStrayRequests();
$manager = app(DurableSwarmManager::class);
$store = app(DurableRunStore::class);
$jobs = [];
foreach (['pending', 'waiting', 'running'] as $status) {
    $response = FakeSequentialSwarm::make()->dispatchDurable(new RunContext('upgrade-'.$status, 'old-'.$status, ['retained' => 42], ['tenant' => 'upgrade']));
    if ($status === 'waiting') {
        $manager->wait($response->runId, 'approval');
    }
    if ($status === 'running') {
        $token = $store->acquireLease($response->runId, 0, 30);
        $store->markRunning($response->runId, $token, 0);
    }
    $jobs[$status] = base64_encode(serialize(new AdvanceDurableSwarm($response->runId, 0, 1789689600000)));
    unset($response);
}
$jobs['invoke'] = base64_encode(serialize((new InvokeSwarm(FakeSequentialSwarm::class, (new RunContext('upgrade-queue', 'old-queue'))->toQueuePayload(), 1789689600000))->onConnection('durable-test')->onQueue('upgrade')));
$jobs['broadcast'] = base64_encode(serialize(new BroadcastSwarm(FakeSequentialSwarm::class, (new RunContext('upgrade-broadcast', 'old-broadcast'))->toQueuePayload(), new Channel('upgrade'), 1789689600000)));
config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');
FakeHierarchicalCoordinator::fake([[
    'start_at' => 'parallel', 'nodes' => [
        'parallel' => ['type' => 'parallel', 'branches' => ['writer'], 'next' => 'finish'],
        'writer' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'old-branch'],
        'finish' => ['type' => 'finish', 'output_from' => 'writer'],
    ],
]]);
(new InvokeSwarm(FakeHierarchicalFullSwarm::class, (new RunContext('upgrade-join', 'old-join'))->toQueuePayload()))->handle(app(SwarmRunner::class));
$branches = $store->branchesFor('upgrade-join');
$jobs['branch'] = base64_encode(serialize(new AdvanceDurableBranch('upgrade-join', $branches[0]['branch_id'], 1789689600000)));
$jobs['resume'] = base64_encode(serialize(new ResumeQueuedHierarchicalSwarm('upgrade-join', 1789689600000)));
$tables = [];
foreach (DB::connection()->getSchemaBuilder()->getTables() as $table) {
    $name = $table['name'];
    if (str_starts_with($name, 'swarm_')) {
        $rows = DB::table($name)->get()->map(fn ($row) => (array) $row)->all();
        if ($rows !== []) {
            $tables[$name] = $rows;
        }
    }
}
$lock = json_decode(file_get_contents('composer.lock'), true, flags: JSON_THROW_ON_ERROR);
$versions = [];
foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
    if (in_array($package['name'], ['laravel/ai', 'laravel/framework', 'orchestra/testbench'], true)) {
        $versions[$package['name']] = ['version' => $package['version'], 'source' => $package['source']];
    }
}
file_put_contents($argv[1], json_encode(['swarm' => 'be7df78e8fde12362cfff9007cfe723d572a5e4f', 'php' => PHP_VERSION, 'dependencies' => $versions, 'jobs' => $jobs, 'tables' => $tables], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
