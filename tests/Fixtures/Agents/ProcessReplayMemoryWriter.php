<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Tools\Remember;
use Illuminate\Support\Facades\DB;
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
    public static int $writeAttempts = 0;

    public static function reset(): void
    {
        static::$writeAttempts = 0;
    }

    /**
     * @param  LaravelAiAgentAttachments  $attachments
     * @param  LaravelAiAgentProvider  $provider
     */
    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        $value = $prompt === 'write:nested'
            ? ['nested' => ['answer' => 42], 'items' => ['one', 'two']]
            : (is_string($prompt) && str_starts_with($prompt, 'write:')
                ? substr($prompt, strlen('write:'))
                : 'retry-value');

        static::$writeAttempts++;
        DB::table('process_replay_write_attempts')->insert([
            'run_id' => ActiveRunContext::current()->runId,
            'agent_class' => static::class,
        ]);

        app(Remember::class)->handle(new Request([
            'key' => 'retry-write',
            'value' => $value,
            'scope' => 'run',
        ]));

        return new AgentResponse('process-replay-writer', is_string($value) ? 'wrote:'.$value : 'wrote:nested', new TextUsage, new Meta('fake', 'test'));
    }
}
