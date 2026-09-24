<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

// An intentionally missing report at the agent contract boundary, not a provider default.
class EmptyUsageAgent extends ScriptedAgent
{
    public static int $calls = 0;

    public function instructions(): string
    {
        return 'Return an empty usage report.';
    }

    protected function reply(string $prompt): string
    {
        return 'empty-report-output';
    }

    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        self::$calls++;

        return new AgentResponse('empty-usage-invocation', 'empty-report-output', new readonly class extends TextUsage
        {
            public function toArray(): array
            {
                return [];
            }
        }, new Meta('fixture', 'empty-usage'));
    }
}
