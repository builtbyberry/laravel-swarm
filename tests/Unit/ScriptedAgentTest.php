<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;
use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\TextUsage;

covers(ScriptedAgent::class);

function nativeInputScriptedAgent(): ScriptedAgent
{
    return new class extends ScriptedAgent
    {
        public int $replies = 0;

        public function instructions(): string
        {
            return 'Scripted input test.';
        }

        protected function reply(string $prompt): string
        {
            $this->replies++;

            return 'reply:'.$prompt;
        }
    };
}

it('retains the Swarm marker and scripted string reply with native text usage', function () {
    $agent = nativeInputScriptedAgent();
    $response = $agent->prompt('a real string');

    expect($agent)->toBeInstanceOf(Agent::class)
        ->toBeInstanceOf(Laravel\Ai\Contracts\Agent::class)
        ->and($response->text)->toBe('reply:a real string')
        ->and($agent->replies)->toBe(1)
        ->and($response->usage)->toBeInstanceOf(TextUsage::class)
        ->and($response->usage->toArray())->toBe([
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_input_tokens' => null,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => null,
        ]);
});

it('rejects richer native input before calling the scripted reply', function (string $kind) {
    $agent = nativeInputScriptedAgent();
    $input = match ($kind) {
        'message' => new UserMessage('do not silently cast this message'),
        'decisions' => Decisions::from(['call' => true]),
        'input' => new class implements AgentInput
        {
            public function message(): ?UserMessage
            {
                throw new LogicException('The scripted agent must not consume native input.');
            }

            public function decisions(): ?Decisions
            {
                throw new LogicException('The scripted agent must not consume decisions.');
            }
        },
    };

    expect(fn () => $agent->prompt($input))->toThrow(RuntimeException::class, 'prompt() requires a string;')
        ->and($agent->replies)->toBe(0);
})->with(['message', 'decisions', 'input']);

it('retains the explicit unsupported mode exception through every widened native verb', function (string $method) {
    $agent = nativeInputScriptedAgent();
    $arguments = [new UserMessage('message')];
    if (str_starts_with($method, 'broadcast')) {
        $arguments[] = new Channel('test');
    }

    expect(fn () => $agent->{$method}(...$arguments))->toThrow(RuntimeException::class, 'does not support '.$method.'()')
        ->and($agent->replies)->toBe(0);
})->with(['stream', 'queue', 'broadcast', 'broadcastNow', 'broadcastOnQueue']);
