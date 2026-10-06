<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\NativeApprovalRecovery;

use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;

#[Provider('openai')]
#[Model('gpt-4.1-mini')]
#[MaxSteps(4)]
final class CrashApprovalAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'Run the controlled approval proof.';
    }

    public function tools(): iterable
    {
        return [new CrashApprovalTool];
    }

    public function middleware(): array
    {
        return [function (PendingStep $step, Closure $next) {
            if (CrashBarrier::matches('after_saved_result_before_model') && $step->isFirstStep()) {
                $saved = DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'))
                    ->where('role', 'assistant')
                    ->where('status', 'paused')
                    ->get(['steps'])
                    ->contains(function (object $row): bool {
                        $steps = json_decode($row->steps, true);

                        return collect($steps)->flatMap(fn (array $item): array => $item['tool_calls'] ?? [])
                            ->contains(fn (array $call): bool => ($call['id'] ?? null) === 'provider-item-approval'
                                && array_key_exists('result', $call));
                    });

                if ($saved) {
                    CrashBarrier::trip('after_saved_result_before_model');
                }
            }

            return $next($step);
        }];
    }
}
