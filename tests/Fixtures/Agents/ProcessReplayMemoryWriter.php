<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Tools\Remember;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Tools\Request;

/**
 * Process-concurrency fixture that writes the value encoded in its prompt.
 *
 * @phpstan-import-type LaravelAiAgentAttachments from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 * @phpstan-import-type LaravelAiAgentProvider from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class ProcessReplayMemoryWriter extends SerializationBoundaryAgent
{
    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        $value = is_string($prompt) && str_starts_with($prompt, 'write:')
            ? substr($prompt, strlen('write:'))
            : 'retry-value';

        app(Remember::class)->handle(new Request([
            'key' => 'retry-write',
            'value' => $value,
            'scope' => 'run',
        ]));

        return new AgentResponse('process-replay-writer', 'wrote:'.$value, new TextUsage, new Meta('fake', 'test'));
    }
}
