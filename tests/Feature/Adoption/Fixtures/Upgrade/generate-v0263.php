<?php

declare(strict_types=1);

// Execute from the isolated, exact v0.26.3 checkout. This script never loads candidate code.
$source = trim((string) shell_exec('git rev-parse HEAD'));
$lockPath = getcwd().'/composer.lock';
$expectedLock = '6a61302fcde29102943661112af82142417a54a57da08bc1e234616afcdec559';
if ($source !== '38b3b649f3416a31c8d1f39ecefd77446e676a3c' || hash_file('sha256', $lockPath) !== $expectedLock || trim((string) shell_exec('git status --porcelain --untracked-files=no')) !== '') {
    throw new RuntimeException('Producer source or lock identity mismatch.');
}
$lock = json_decode(file_get_contents($lockPath), true, flags: JSON_THROW_ON_ERROR);
$installed = json_decode(file_get_contents(getcwd().'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR)['packages'];
$dependencies = [];
foreach (['laravel/ai', 'laravel/framework', 'orchestra/testbench'] as $name) {
    $locked = array_values(array_filter(array_merge($lock['packages'], $lock['packages-dev']), fn (array $package): bool => $package['name'] === $name))[0];
    $actual = array_values(array_filter($installed, fn (array $package): bool => $package['name'] === $name))[0];
    if ($locked['version'] !== $actual['version'] || $locked['source'] !== $actual['source']) {
        throw new RuntimeException('Producer installed dependency mismatch.');
    }
    $dependencies[$name] = ['version' => $actual['version'], 'source' => $actual['source']];
}
if ($dependencies['laravel/ai']['version'] !== 'v0.11.2' || $dependencies['laravel/ai']['source']['reference'] !== 'ee2c5162838d440c4e2e629ea93c8c87e838eaed' || $dependencies['laravel/ai']['source']['url'] !== 'https://github.com/laravel/ai.git') {
    throw new RuntimeException('Producer requires official Laravel AI v0.11.2.');
}
if (is_file($argv[1] ?? '')) {
    throw new RuntimeException('Refusing to overwrite a frozen fixture.');
}
require getcwd().'/vendor/autoload.php';

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\TestCase;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

$harness = new class('fixture') extends TestCase
{
    public function boot(): void
    {
        $this->setUp();
    }
};
$harness->boot();
Carbon::setTestNow('2026-09-23 00:00:00 UTC');
config()->set('app.key', 'base64:'.base64_encode(str_repeat('v', 32)));
app()->forgetInstance('encrypter');
config()->set('swarm.persistence.driver', 'database');
config()->set('swarm.persistence.encrypt_at_rest', true);
config()->set('swarm.durable.queue.connection', 'durable-test');
config()->set('queue.default', 'durable-test');
Http::preventStrayRequests();
$fake = static function (): void {
    FakeResearcher::fake([new AgentResponse('old-research', 'old-researched', new Usage(11, 3, 2, 4, 1), new Meta('fake', 'old'))]);
    FakeWriter::fake([new AgentResponse('old-writer', 'old-written', new Usage(13, 5, 0, 2, 1), new Meta('fake', 'old'))]);
    FakeEditor::fake([new AgentResponse('old-editor', 'old-edited', new Usage(17, 7, 0, 0, 2), new Meta('fake', 'old'))]);
};
$manager = app(DurableSwarmManager::class);
$store = app(DurableRunStore::class);
$jobs = [];
$fake();
FakeSequentialSwarm::make()->prompt(new RunContext('v0263-completed', 'old completed input'));
foreach (['pending', 'waiting', 'running'] as $status) {
    $fake();
    $id = 'v0263-'.$status;
    $response = FakeSequentialSwarm::make()->dispatchDurable(new RunContext($id, 'old '.$status.' input'));
    (new AdvanceDurableSwarm($id, 0))->handle($manager);
    if ($status === 'waiting') {
        $manager->wait($id, 'operator-release');
    }
    if ($status === 'running') {
        $token = $store->acquireLease($id, 1, 30);
        $store->markRunning($id, $token, 1);
    }
    $jobs[$status] = base64_encode(serialize(new AdvanceDurableSwarm($id, 1, 1790121600000)));
    unset($response);
}
$jobs['invoke'] = base64_encode(serialize((new InvokeSwarm(FakeSequentialSwarm::class, (new RunContext('v0263-queue', 'old queue input'))->toQueuePayload(), 1790121600000))->onConnection('durable-test')->onQueue('upgrade')));
$jobs['broadcast'] = base64_encode(serialize(new BroadcastSwarm(FakeSequentialSwarm::class, (new RunContext('v0263-broadcast', 'old broadcast input'))->toQueuePayload(), new Channel('upgrade'), 1790121600000)));
$fake();
$id = FakeParallelSwarm::make()->dispatchDurable(new RunContext('v0263-parallel', 'old parallel input'))->runId;
(new AdvanceDurableSwarm($id, 0))->handle($manager);
$branches = $store->branchesFor($id, 'parallel');
(new AdvanceDurableBranch($id, $branches[0]['branch_id']))->handle($manager);
$jobs['parallel_branch'] = base64_encode(serialize(new AdvanceDurableBranch($id, $branches[1]['branch_id'], 1790121600000)));
$jobs['parallel_join'] = base64_encode(serialize(new AdvanceDurableSwarm($id, (int) $store->find($id)['next_step_index'], 1790121600000)));
$fake();
config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');
$plan = ['start_at' => 'parallel', 'nodes' => [
    'parallel' => ['type' => 'parallel', 'branches' => ['writer', 'editor'], 'next' => 'finish'],
    'writer' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'old branch writer'],
    'editor' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'old branch editor'],
    'finish' => ['type' => 'finish', 'output_from' => 'editor'],
]];
FakeHierarchicalCoordinator::fake([new StructuredAgentResponse('old-coordinator', $plan, json_encode($plan, JSON_THROW_ON_ERROR), new Usage(19, 11, 0, 3, 1), new Meta('fake', 'old'))]);
(new InvokeSwarm(FakeHierarchicalFullSwarm::class, (new RunContext('v0263-join', 'old join input'))->toQueuePayload()))->handle(app(SwarmRunner::class));
$branches = $store->branchesFor('v0263-join');
(new AdvanceDurableBranch('v0263-join', $branches[0]['branch_id']))->handle($manager);
$jobs['branch'] = base64_encode(serialize(new AdvanceDurableBranch('v0263-join', $branches[1]['branch_id'], 1790121600000)));
$jobs['resume'] = base64_encode(serialize(new ResumeQueuedHierarchicalSwarm('v0263-join', 1790121600000)));
$fake();
$stream = FakeSequentialSwarm::make()->stream(new RunContext('v0263-stream', 'old stream input'))->storeForReplay();
iterator_to_array($stream);
$event = new SwarmToolResult('old-denied-event', 'v0263-stream', 2, FakeEditor::class, new ToolResult('old-call', 'old-tool', ['query' => 'retained'], 'denied', denied: true), false, 'denied', 1790121600);
$event->invocationId = 'old-editor';
app(StreamEventStore::class)->record('v0263-stream', $event, 86400);
$tables = [];
foreach (DB::connection()->getSchemaBuilder()->getTables() as $table) {
    if (str_starts_with($table['name'], 'swarm_')) {
        $rows = DB::table($table['name'])->get()->map(fn ($row) => (array) $row)->all();
        if ($rows !== []) {
            $tables[$table['name']] = $rows;
        }
    }
}
$histories = [];
foreach (['completed', 'pending', 'waiting', 'running', 'parallel', 'join', 'stream'] as $name) {
    $histories[$name] = app(RunHistoryStore::class)->find('v0263-'.$name);
}
Http::assertNothingSent();
file_put_contents($argv[1], json_encode([
    'swarm' => $source, 'php' => PHP_VERSION,
    'manifest_sha256' => hash_file('sha256', getcwd().'/composer.json'),
    'lock_sha256' => hash_file('sha256', $lockPath), 'generator_sha256' => hash_file('sha256', __FILE__),
    'dependencies' => $dependencies, 'jobs' => $jobs, 'tables' => $tables, 'histories' => $histories,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
