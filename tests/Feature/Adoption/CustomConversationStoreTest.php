<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\ConversationAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeWire;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\WorkflowTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;

covers(SequentialRunner::class);

it('uses an application ConversationStore with native history roles and tool result payloads', function () {
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);
    config()->set('swarm.capture.artifacts', false);
    config()->set('swarm.capture.active_context', false);
    config()->set('swarm.persistence.driver', 'database');
    $store = new class(app(DatabaseConversationStore::class)) implements ConversationStore
    {
        public array $calls = [];

        public function __construct(private DatabaseConversationStore $inner) {}

        public function latestConversationId(string $participantType, string|int $participantId): ?string
        {
            $this->calls[] = 'latest';

            return $this->inner->latestConversationId($participantType, $participantId);
        }

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
        {
            $this->calls[] = 'conversation';

            return $this->inner->storeConversation($participantType, $participantId, $title);
        }

        public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
        {
            $this->calls[] = 'user';

            return $this->inner->storeUserMessage($conversationId, $participantType, $participantId, $prompt);
        }

        public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
        {
            $this->calls[] = 'assistant';

            return $this->inner->storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response);
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            $this->calls[] = 'history';

            return $this->inner->getLatestConversationMessages($conversationId, $limit);
        }

        public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
        {
            throw new RuntimeException('No native approval integration is exercised.');
        }
    };
    app()->instance(ConversationStore::class, $store);
    Http::preventStrayRequests();
    $bodies = [];
    Http::fake(function (Request $request) use (&$bodies) {
        $bodies[] = $request->data();

        return Http::response(NativeWire::response('answer-'.count($bodies), tool: count($bodies) === 1));
    });
    // Native conversation IDs normally come from the custom store.
    (require base_path('vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php'))->up();
    $id = $store->storeConversation(null, null, 'custom');
    WorkflowTool::$effects = [];
    $agent = (new class extends ConversationAgent implements HasTools
    {
        public function tools(): iterable
        {
            return [new WorkflowTool];
        }
    })->continue($id);
    $first = app(SwarmRunner::class)->agent($agent)->prompt('first');
    $second = app(SwarmRunner::class)->agent($agent)->prompt('second');
    expect($first->output)->toBe('answer-2')->and($second->output)->toBe('answer-3');
    expect($store->calls)->toBe(['conversation', 'history', 'user', 'assistant', 'history', 'user', 'assistant']);
    expect(array_column($bodies[2]['input'], 'role'))->toBe(['system', 'user', 'assistant', 'user']);
    expect(json_encode($bodies[2], JSON_THROW_ON_ERROR))->toContain('first', 'answer-2', 'second');
    expect($bodies[2]['input'])->toContain(['type' => 'function_call_output', 'call_id' => 'call', 'output' => 'effect:effect-secret']);
    expect(WorkflowTool::$effects)->toBe(['effect-secret']);
    $stored = DB::table('agent_conversation_messages')->where('conversation_id', $id)->where('tool_results', '!=', '[]')->sole();
    expect(json_decode($stored->tool_results, true, flags: JSON_THROW_ON_ERROR)[0])->toMatchArray([
        'id' => 'item', 'result_id' => 'call', 'name' => 'WorkflowTool', 'arguments' => ['value' => 'effect-secret'], 'result' => 'effect:effect-secret',
    ]);
    expect(DB::table('agent_conversation_messages')->where('conversation_id', $id)->count())->toBe(4)
        ->and(DB::table('swarm_run_histories')->count())->toBe(2);
    $historyStore = app(RunHistoryStore::class);
    expect($historyStore)->toBeInstanceOf(DatabaseRunHistoryStore::class);
    foreach ([$first, $second] as $response) {
        $history = $historyStore->find($response->metadata['run_id']);
        expect($history)->not->toBeNull()
            ->and($history['status'])->toBe('completed')
            ->and($history['context']['input'])->toBe('[redacted]')
            ->and($history['output'])->toBe('[redacted]')
            ->and($history['artifacts'])->toBe([])
            ->and($history['steps'])->toHaveCount(1)
            ->and($history['steps'][0]['input'])->toBe('[redacted]')
            ->and($history['steps'][0]['output'])->toBe('[redacted]')
            ->and($history['steps'][0]['artifacts'])->toBe([]);
    }
    Http::assertSentCount(3);
    WorkflowTool::$effects = [];
});
