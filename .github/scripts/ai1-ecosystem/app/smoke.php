<?php

use App\Ai\Agents\SmokeAgent;
use App\Ai\Swarms\SmokeSwarm;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use BuiltByBerry\LaravelSwarmFilament\SwarmFilamentServiceProvider;
use BuiltByBerry\LaravelSwarmMcp\SwarmMcpServiceProvider;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorMemoryReader;
use BuiltByBerry\LaravelSwarmMemoryVector\SwarmMemoryVectorServiceProvider;
use BuiltByBerry\LaravelSwarmPulse\Livewire\SwarmRuns;
use BuiltByBerry\LaravelSwarmPulse\PulseServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Pulse\Facades\Pulse;
use Symfony\Component\HttpFoundation\Response;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
});

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

function nativeReply(string $text, bool $stream): array|string
{
    $item = ['type' => 'message', 'id' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]];
    $response = ['id' => 'response', 'model' => 'gpt-4.1-mini', 'status' => 'completed', 'output' => [$item], 'usage' => ['input_tokens' => 2, 'output_tokens' => 3]];
    if (! $stream) {
        return $response;
    }
    $events = [
        ['type' => 'response.created', 'response' => $response],
        ['type' => 'response.output_item.added', 'item' => $item, 'output_index' => 0],
        ['type' => 'response.output_text.delta', 'item_id' => 'message', 'delta' => $text],
        ['type' => 'response.output_text.done', 'item_id' => 'message', 'text' => $text],
        ['type' => 'response.completed', 'response' => $response],
    ];

    return implode('', array_map(fn ($event) => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n", $events));
}

function vector(int $axis): array
{
    $vector = array_fill(0, 16, 0.0);
    $vector[$axis] = 1.0;

    return $vector;
}

$providers = [
    'builtbyberry/laravel-swarm' => SwarmServiceProvider::class,
    'builtbyberry/laravel-swarm-pulse' => PulseServiceProvider::class,
    'builtbyberry/laravel-swarm-filament' => SwarmFilamentServiceProvider::class,
    'builtbyberry/laravel-swarm-mcp' => SwarmMcpServiceProvider::class,
    'builtbyberry/laravel-swarm-memory-vector' => SwarmMemoryVectorServiceProvider::class,
];
$discovered = require __DIR__.'/bootstrap/cache/packages.php';
foreach ($providers as $package => $provider) {
    check(in_array($provider, $discovered[$package]['providers'] ?? [], true), 'Missing discovered provider: '.$package);
    check($app->getLoadedProviders()[$provider] ?? false, 'Unbooted provider: '.$package);
}
foreach (['laravel/ai', 'laravel/mcp', 'laravel/pulse', 'livewire/livewire', 'filament/filament'] as $package) {
    check(isset($discovered[$package]), 'Missing native discovery: '.$package);
}

Http::preventStrayRequests();
$calls = ['responses' => 0, 'embeddings' => 0];
Http::fake([
    'https://api.openai.com/v1/responses' => function (Request $request) use (&$calls) {
        $calls['responses']++;
        check($request['model'] === 'gpt-4.1-mini', 'Native response model');
        check(is_array($request['input']) && count($request['input']) > 0, 'Native response input');

        return Http::response(nativeReply('smoke-answer', (bool) ($request['stream'] ?? false)));
    },
    'https://api.openai.com/v1/embeddings' => function (Request $request) use (&$calls) {
        $calls['embeddings']++;
        check($request['model'] === 'text-embedding-3-small' && $request['dimensions'] === 16, 'Native embedding model/dimensions');
        check(is_array($request['input']) && count($request['input']) === 1, 'Native embedding input');
        $text = $request['input'][0];
        $vector = match ($text) {
            'taco lunch', 'new lunch' => vector(1),
            'query the launch' => [0.9, 0.1, ...array_fill(0, 14, 0.0)],
            default => vector(0),
        };

        return Http::response(['data' => [['embedding' => $vector]], 'usage' => ['prompt_tokens' => 4, 'total_tokens' => 4]]);
    },
]);

$expectedOutput = getenv('C5_EXPECTED_OUTPUT') ?: 'smoke-answer';
$runner = app(SwarmRunner::class);
$response = $runner->sequential([new SmokeAgent, new SmokeAgent])->prompt('task');
check($response->output === $expectedOutput, 'prompt output');
check($response->usage['input_tokens'] === 4 && $response->usage['output_tokens'] === 6, 'two-step native usage');
$history = app(SwarmHistory::class)->find($response->context->runId);
check($history['status'] === 'completed' && $history['output'] === $expectedOutput, 'persisted prompt history');
check($history['usage']['input_tokens'] === 4 && $history['usage']['output_tokens'] === 6, 'persisted prompt usage');
$stream = $runner->sequential([new SmokeAgent, new SmokeAgent])->stream('task')->storeForReplay();
$events = iterator_to_array($stream);
check(count($events) > 0 && $stream->streamedResponse->output === $expectedOutput, 'stream output');
check($stream->streamedResponse->usage['input_tokens'] === 4 && $stream->streamedResponse->usage['output_tokens'] === 6, 'stream native usage');
$beforeReplay = $calls;
$replay = app(SwarmHistory::class)->replay($stream->runId);
$replayed = iterator_to_array($replay);
check(array_map(fn ($event) => $event->toArray(), $replayed) === array_map(fn ($event) => $event->toArray(), $events), 'stored replay equality');
check($replay->streamedResponse->output === $expectedOutput && $calls === $beforeReplay, 'replay output without HTTP');

Pulse::ingest();
check(DB::table('pulse_aggregates')->where('type', 'swarm_run')->where('key', 'swarm_run|'.$history['swarm_class'].'|sequential|completed')->exists(), 'real lifecycle events reached Pulse');
$card = new class extends SwarmRuns
{
    public function snapshot(): Collection
    {
        return $this->resolveRuns();
    }
};
$row = $card->snapshot()->firstWhere('swarmClass', $history['swarm_class']);
check($row !== null && $row->totalRuns === 2 && $row->failures === 0, 'companion aggregate reader');

$record = SwarmRun::query()->findOrFail($response->context->runId);
check(SwarmRunResource::tokensLabel($record) === '10', 'Filament persisted native usage reader');
foreach ([[['input_tokens' => 0, 'output_tokens' => 0], '0'], [[], 'Unavailable']] as [$usage, $expected]) {
    $record = new SwarmRun;
    $record->setRawAttributes(['run_id' => 'presentation-only', 'usage' => json_encode($usage, JSON_THROW_ON_ERROR)]);
    check(SwarmRunResource::tokensLabel($record) === $expected, 'Filament availability reader');
}

Auth::viaRequest('c5', fn (Illuminate\Http\Request $request) => $request->bearerToken() === 'c5-disposable-token' ? new GenericUser(['id' => 1]) : null);
function mcpRequest(string $method, array $params = [], bool $authorized = true): Response
{
    Auth::forgetGuards();
    $message = ['jsonrpc' => '2.0', 'id' => 'c5-request', 'method' => $method, 'params' => [...$params, '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []]]];
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28', 'HTTP_MCP_METHOD' => $method];
    if (isset($params['uri'])) {
        $server['HTTP_MCP_NAME'] = $params['uri'];
    }
    if ($authorized) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer c5-disposable-token';
    }
    $request = Illuminate\Http\Request::create('/swarm-mcp', 'POST', server: $server, content: json_encode($message, JSON_THROW_ON_ERROR));
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
}
function mcpResult(string $method, array $params = []): array
{
    $response = mcpRequest($method, $params);
    check($response->getStatusCode() === 200, 'MCP HTTP '.$method.': '.$response->getContent());
    $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    check($body['id'] === 'c5-request', 'MCP correlation');

    return $body['result'];
}
check(mcpRequest('resources/list', authorized: false)->getStatusCode() === 401, 'MCP rejects guest');
check(mcpResult('server/discover')['supportedVersions'] === ['2026-07-28'], 'native MCP protocol');
check(array_column(mcpResult('resources/list')['resources'], 'uri') === ['swarm://runs', 'swarm://audit-outbox/health'], 'MCP fixed resources');
check(array_column(mcpResult('resources/templates/list')['resourceTemplates'], 'uriTemplate') === ['swarm://runs/{runId}', 'swarm://durable-runs/{runId}', 'swarm://audit-outbox/queue/{state}', 'swarm://audit-outbox/records/{id}'], 'MCP templates');
foreach (['tools', 'prompts'] as $kind) {
    check(mcpResult($kind.'/list')[$kind] === [], 'MCP empty '.$kind);
}
$uri = 'swarm://runs/'.$response->context->runId;
$content = mcpResult('resources/read', ['uri' => $uri])['contents'][0];
check($content['uri'] === $uri, 'MCP resource URI');
$exposed = json_decode($content['text'], true, flags: JSON_THROW_ON_ERROR);
check($exposed['run_id'] === $response->context->runId && $exposed['status'] === 'completed' && $exposed['output'] === $expectedOutput, 'MCP real persisted run');
check($exposed['usage']['input_tokens'] === 4 && $exposed['usage']['output_tokens'] === 6, 'MCP native usage');

$runId = 'c5-vector-'.Str::uuid();
$context = new RunContext($runId, 'recall');
app(RunHistoryStore::class)->start($runId, SmokeSwarm::class, 'sequential', $context, [], 3600);
$memory = app(SwarmMemory::class);
$beforeVector = $calls['embeddings'];
$memory->put(MemoryScope::Run, $runId, 'c5-rocket', 'rocket launch');
$memory->put(MemoryScope::Run, $runId, 'c5-lunch', 'taco lunch');
foreach (['swarm_memories', 'swarm_memory_vectors'] as $table) {
    check(DB::table($table)->where('scope_id', $runId)->count() === 2, 'vector persisted '.$table);
}
ActiveRunContext::enter($runId, SmokeSwarm::class, $context);
try {
    $reader = app(VectorMemoryReader::class);
    check($reader->search(MemoryScope::Run, 'query the launch', 2) === "c5-rocket: rocket launch\nc5-lunch: taco lunch", 'vector exact ranking');
    $memory->put(MemoryScope::Run, $runId, 'c5-rocket', 'new lunch');
    check($memory->get(MemoryScope::Run, $runId, 'c5-rocket') === 'new lunch', 'canonical vector update');
    $query = DB::table('swarm_memory_vectors')->where('scope_id', $runId)->where('key', 'c5-rocket');
    check($query->count() === 1, 'vector replacement cardinality');
    check(array_map('floatval', json_decode($query->value('embedding'), true, flags: JSON_THROW_ON_ERROR)) === vector(1), 'updated native vector');
    $memory->forget(MemoryScope::Run, $runId, 'c5-rocket');
    foreach (['swarm_memories', 'swarm_memory_vectors'] as $table) {
        check(! DB::table($table)->where('scope_id', $runId)->where('key', 'c5-rocket')->exists(), 'vector forget '.$table);
    }
    check($reader->search(MemoryScope::Run, 'query the launch') === 'c5-lunch: taco lunch', 'vector ranking after forget');
} finally {
    ActiveRunContext::exit();
}
check($calls['embeddings'] - $beforeVector === 5 && $calls['responses'] === 4, 'native HTTP request counts');
echo json_encode(['status' => 'passed', 'providers' => $providers, 'prompt_run_id' => $response->context->runId, 'stream_run_id' => $stream->runId, 'replay_events' => count($replayed), 'pulse_runs' => $row->totalRuns, 'http_faked_requests' => $calls, 'smokes' => ['core', 'pulse', 'filament', 'native-mcp-http', 'native-vector']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
