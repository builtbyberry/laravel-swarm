<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use Closure;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;

#[Provider('openai')]
#[Model('gpt-4.1-mini')]
#[MaxTokens(321)]
class NativeHttpAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Native boundary test.';
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [new ApprovalTool, new EffectTool];
    }

    public function middleware(): array
    {
        return [fn (AgentPrompt $prompt, Closure $next) => $next($prompt->revise('revised '.$prompt->prompt))];
    }
}
