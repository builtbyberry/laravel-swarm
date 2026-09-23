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
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
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

    public static array $generationSteps = [];

    public function instructions(): string
    {
        return 'Preservation worker.';
    }

    public function middleware(): array
    {
        return [
            function (PendingStep $step, Closure $next) {
                self::$trace[] = 'outer:'.$this->userMessage($step)->content;
                self::$generationSteps[] = [
                    'number' => $step->number,
                    'first' => $step->isFirstStep(),
                    'final' => $step->isFinalStep,
                    'completed' => count($step->steps),
                ];
                if (config('tests.adoption.middleware_throw')
                    || config('tests.adoption.middleware_throw_step') === $step->number) {
                    throw new RuntimeException('middleware stopped');
                }
                if (config('tests.adoption.middleware_short_circuit')) {
                    return new StepResponse('middleware answer', [], FinishReason::Stop, new TextUsage, new Meta($step->provider, $step->model));
                }

                return $next($this->reviseUserMessage($step, 'outer '));
            },
            function (PendingStep $step, Closure $next) {
                self::$trace[] = 'inner:'.$this->userMessage($step)->content;

                return $next($this->reviseUserMessage($step, 'inner '));
            },
        ];
    }

    private function userMessage(PendingStep $step): UserMessage
    {
        foreach (array_reverse($step->messages) as $message) {
            if ($message instanceof UserMessage) {
                return $message;
            }
        }

        throw new RuntimeException('Workflow fixture requires a user message.');
    }

    private function reviseUserMessage(PendingStep $step, string $prefix): PendingStep
    {
        $original = $this->userMessage($step);
        $messages = $step->messages;
        foreach ($messages as $index => $message) {
            if ($message === $original) {
                $messages[$index] = new UserMessage($prefix.$message->content, $message->attachments);
            }
        }

        return $step->withMessages($messages);
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
