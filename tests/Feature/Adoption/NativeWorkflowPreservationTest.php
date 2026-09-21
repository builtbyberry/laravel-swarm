<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Runners\SequentialStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\ConversationAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\CoordinatorAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeWire;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\UnsupportedSearchAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\WorkflowAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\WorkflowTool;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksInputWhenMatches;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksOutputWhenContains;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksStepWhenIndex;
use BuiltByBerry\LaravelSwarm\Tests\Support\GuardrailContainer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;

covers(SequentialRunner::class, SequentialStreamRunner::class, HierarchicalRoutePlanner::class);

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('ai.providers.gemini.key', 'test-key');
    config()->set('ai.conversations.generate_title', false);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Http::preventStrayRequests();
    WorkflowAgent::$trace = [];
    WorkflowTool::$effects = [];
});

it('preserves native middleware order options prompt identity and successful usage', function (string $mode) {
    $prompts = [];
    $completed = [];
    app('events')->listen($mode === 'prompt' ? PromptingAgent::class : StreamingAgent::class, function ($event) use (&$prompts) {
        $prompts[] = $event->prompt;
    });
    app('events')->listen($mode === 'prompt' ? AgentPrompted::class : AgentStreamed::class, function ($event) use (&$completed) {
        $completed[] = $event;
    });
    Http::fake(function (Request $request) use ($mode) {
        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and($request['model'])->toBe('gpt-4.1-mini')
            ->and($request['max_output_tokens'])->toBe(321)
            ->and($request['temperature'])->toBe(0.25)
            ->and($request['top_p'])->toBe(0.75)
            ->and($request['metadata'])->toBe(['preservation' => 'native-options'])
            ->and($request['input'][1]['content'][0]['text'])->toBe('inner outer task');

        return Http::response(NativeWire::response(stream: $mode === 'stream'));
    });
    $response = app(SwarmRunner::class)->agent(new WorkflowAgent)->{$mode}('task');
    if ($mode === 'stream') {
        expect(WorkflowAgent::$trace)->toBe([]);
        iterator_to_array($response);
        $response = $response->streamedResponse;
    }
    expect(WorkflowAgent::$trace)->toBe(['outer:task', 'inner:outer task'])
        ->and($prompts)->toHaveCount(1)->and($prompts[0]->prompt)->toBe('inner outer task')
        ->and($prompts[0]->agent)->toBeInstanceOf(WorkflowAgent::class)
        ->and($completed)->toHaveCount(1)
        ->and($completed[0]->invocationId)->toBe($prompts[0]->invocationId)
        ->and($response->output)->toBe('native-answer')
        ->and($response->usage['prompt_tokens'])->toBe(2)
        ->and($response->usage['completion_tokens'])->toBe(3);
    Http::assertSentCount(1);
})->with(['prompt', 'stream']);

it('preserves guardrail and native middleware failure ordering', function (string $mode, string $boundary) {
    Event::fake([SwarmCompleted::class, SwarmStepCompleted::class]);
    if ($boundary === 'input') {
        config()->set('swarm.guardrails.input', [BlocksInputWhenMatches::class]);
        app()->bind(BlocksInputWhenMatches::class, fn () => new BlocksInputWhenMatches('task'));
    } elseif ($boundary === 'step') {
        config()->set('swarm.guardrails.step', [BlocksStepWhenIndex::class]);
        app()->bind(BlocksStepWhenIndex::class, fn () => new BlocksStepWhenIndex(0));
    } elseif ($boundary === 'output') {
        config()->set('swarm.guardrails.output', [BlocksOutputWhenContains::class]);
        app()->bind(BlocksOutputWhenContains::class, fn () => new BlocksOutputWhenContains('native-answer'));
    } else {
        config()->set('tests.adoption.middleware_throw', true);
    }
    GuardrailContainer::refresh(app());
    Http::fake(['*' => Http::response(NativeWire::response(stream: $mode === 'stream'))]);
    expect(function () use ($mode) {
        $response = app(SwarmRunner::class)->agent(new WorkflowAgent)->{$mode}('task');
        if ($mode === 'stream') {
            iterator_to_array($response);
        }
    })->toThrow($boundary === 'middleware' ? RuntimeException::class : GuardrailViolation::class);
    expect(WorkflowAgent::$trace)->toHaveCount(match ($boundary) {
        'input' => 0, 'middleware' => 1, default => 2
    });
    Http::assertSentCount(in_array($boundary, ['input', 'middleware'], true) ? 0 : 1);
    Event::assertNotDispatched(SwarmCompleted::class);
    if ($boundary !== 'output') {
        Event::assertNotDispatched(SwarmStepCompleted::class);
    }
})->with(['prompt', 'stream'])->with(['input', 'middleware', 'step', 'output']);

it('uses native deferred discovery and returns the paired tool result on the wire', function (string $mode) {
    config()->set('tests.adoption.tools', 'search');
    $requests = 0;
    Http::fake(function (Request $request) use (&$requests, $mode) {
        $requests++;
        expect($request['tools'][0]['type'])->toBe('tool_search')
            ->and($request['tools'][1]['name'])->toBe('WorkflowTool')
            ->and($request['tools'][1]['defer_loading'])->toBeTrue()
            ->and($request['tools'][1]['parameters']['required'])->toBe(['value'])
            ->and($request['tools'][1]['parameters']['properties']['value']['type'])->toBe('string');
        if ($requests === 2) {
            expect($request['input'])->toContain(['type' => 'function_call_output', 'call_id' => 'call', 'output' => 'effect:effect-secret']);
        }

        return Http::response(NativeWire::response(tool: $requests === 1, stream: $mode === 'stream'));
    });
    $response = app(SwarmRunner::class)->agent(new WorkflowAgent)->{$mode}('task');
    if ($mode === 'stream') {
        iterator_to_array($response);
        $response = $response->streamedResponse;
    }
    expect(WorkflowTool::$effects)->toBe(['effect-secret'])
        ->and($response->output)->toBe('native-answer')
        ->and($response->usage['prompt_tokens'])->toBe(4)
        ->and($response->usage['completion_tokens'])->toBe(6);
    Http::assertSentCount(2);
})->with(['prompt', 'stream']);

it('retains native discovery restrictions before requests and effects', function (string $mode, string $restriction) {
    config()->set('tests.adoption.tools', $restriction === 'duplicate' ? 'duplicate' : 'search');
    if ($restriction === 'stateless') {
        config()->set('ai.providers.openai.store', false);
    }
    Http::fake();
    $agent = $restriction === 'unsupported' ? new UnsupportedSearchAgent : new WorkflowAgent;
    expect(function () use ($mode, $agent) {
        $response = app(SwarmRunner::class)->agent($agent)->{$mode}('task');
        if ($mode === 'stream') {
            iterator_to_array($response);
        }
    })->toThrow(LogicException::class, match ($restriction) {
        'duplicate' => 'single tool search wrapper',
        'stateless' => 'store=false',
        default => 'does not support tool search',
    });
    Http::assertNothingSent();
    expect(WorkflowTool::$effects)->toBe([]);
})->with(['prompt', 'stream'])->with(['unsupported', 'duplicate', 'stateless']);

it('passes native structured JSON through route validation before worker invocation', function (string $case) {
    $plan = ['start_at' => 'worker', 'nodes' => ['worker' => ['type' => 'worker', 'agent' => WorkflowAgent::class, 'prompt' => 'routed-task']]];
    if ($case === 'unknown') {
        $plan['nodes']['worker']['agent'] = 'UnknownWorker';
    } elseif ($case === 'cycle') {
        $plan['nodes']['worker']['next'] = 'worker';
    }
    $text = match ($case) {
        'malformed' => '{broken', 'shape' => '{}', default => json_encode($plan, JSON_THROW_ON_ERROR)
    };
    $requests = 0;
    Http::fake(function (Request $request) use (&$requests, $text) {
        $requests++;
        if ($requests === 1) {
            expect($request['text']['format']['type'])->toBe('json_schema')
                ->and($request['text']['format']['schema']['required'])->toBe(['start_at', 'nodes'])
                ->and($request['text']['format']['schema']['properties']['start_at']['type'])->toBe('string');
        } else {
            expect($request['input'][1]['content'][0]['text'])->toContain('routed-task');
        }

        return Http::response(NativeWire::response($requests === 1 ? $text : 'worker-result'));
    });
    $run = fn () => app(SwarmRunner::class)->hierarchical(new CoordinatorAgent, [new WorkflowAgent])->prompt('task');
    if ($case === 'valid') {
        $response = $run();
        expect($response->output)->toBe('worker-result')
            ->and($response->metadata['executed_node_ids'])->toBe(['worker'])
            ->and($response->usage['prompt_tokens'])->toBe(4);
    } else {
        expect($run)->toThrow(SwarmException::class, match ($case) {
            'malformed', 'shape' => 'non-empty [start_at]',
            'unknown' => 'references unknown worker agent class',
            'cycle' => 'must be acyclic',
        });
        expect(WorkflowAgent::$trace)->toBe([]);
    }
    Http::assertSentCount($case === 'valid' ? 2 : 1);
})->with(['valid', 'malformed', 'shape', 'unknown', 'cycle']);

it('keeps native conversation opt in roles isolation and separate storage privacy', function (string $mode) {
    foreach (['inputs', 'outputs', 'artifacts', 'active_context'] as $capture) {
        config()->set('swarm.capture.'.$capture, false);
    }
    config()->set('swarm.persistence.encrypt_at_rest', true);
    (require base_path('vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php'))->up();
    $inputs = [];
    Http::fake(function (Request $request) use (&$inputs, $mode) {
        $inputs[] = $request['input'];

        return Http::response(NativeWire::response('answer-'.count($inputs), stream: $mode === 'stream'));
    });
    $first = (new ConversationAgent)->forUser((object) ['id' => 1]);
    $second = (new ConversationAgent)->forUser((object) ['id' => 2]);
    foreach ([[$first, 'first-secret'], [$first, 'next-secret'], [$second, 'other-secret'], [new ConversationAgent, 'unremembered']] as [$agent, $task]) {
        $response = app(SwarmRunner::class)->agent($agent)->{$mode}($task);
        if ($mode === 'stream') {
            iterator_to_array($response);
            $response = $response->streamedResponse;
        }
        expect($response->output)->toStartWith('answer-');
    }
    expect(array_column($inputs[1], 'role'))->toBe(['system', 'user', 'assistant', 'user'])
        ->and($inputs[1][1]['content'][0]['text'])->toBe('first-secret')
        ->and($inputs[1][2]['content'][0]['text'])->toBe('answer-1')
        ->and($inputs[1][3]['content'][0]['text'])->toBe('next-secret')
        ->and(array_column($inputs[2], 'role'))->toBe(['system', 'user'])
        ->and($inputs[2][1]['content'][0]['text'])->toBe('other-secret')
        ->and(array_column($inputs[3], 'role'))->toBe(['system', 'user'])
        ->and($first->currentConversation())->not->toBe($second->currentConversation());
    expect(DB::table('agent_conversations')->count())->toBe(2)
        ->and(DB::table('agent_conversation_messages')->count())->toBe(6)
        ->and(DB::table('agent_conversation_messages')->where('role', 'user')->pluck('content')->all())->toBe(['first-secret', 'next-secret', 'other-secret']);
    Http::assertSentCount(4);
})->with(['prompt', 'stream']);
