<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures;

use Closure;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\TopP;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\ToolSearch;
use RuntimeException;

#[Provider('openai')]
#[Model('gpt-4.1-mini')]
#[MaxTokens(321)]
#[Temperature(0.25)]
#[TopP(0.75)]
class WorkflowAgent implements Agent, HasMiddleware, HasProviderOptions, HasTools
{
    use Promptable;

    public static array $trace = [];

    public function instructions(): string
    {
        return 'Preservation worker.';
    }

    public function middleware(): array
    {
        return [
            function (AgentPrompt $prompt, Closure $next) {
                self::$trace[] = 'outer:'.$prompt->prompt;
                if (config('tests.adoption.middleware_throw')) {
                    throw new RuntimeException('middleware stopped');
                }

                return $next($prompt->revise('outer '.$prompt->prompt));
            },
            function (AgentPrompt $prompt, Closure $next) {
                self::$trace[] = 'inner:'.$prompt->prompt;

                return $next($prompt->revise('inner '.$prompt->prompt));
            },
        ];
    }

    public function tools(): iterable
    {
        return match (config('tests.adoption.tools')) {
            'direct' => [new WorkflowTool],
            'search' => [new ToolSearch([new WorkflowTool])],
            'duplicate' => [new ToolSearch([new WorkflowTool]), new ToolSearch([new WorkflowTool])],
            default => [],
        };
    }

    public function providerOptions(Lab|string $provider): array
    {
        return ['metadata' => ['preservation' => 'native-options']];
    }
}
