<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Concerns;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Memory\RunContextMemoryReader;
use Illuminate\Container\Container;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;

/**
 * Opt-in trait for a laravel/ai agent that also implements
 * {@see Conversational}.
 *
 * Inside a swarm run, {@see messages()} renders the active swarm's
 * propagation-policy memory view as laravel/ai {@see Message}s, so the agent
 * sees the run's shared context as a real conversation rather than only the
 * flattened prompt string. laravel/ai prepends these messages before the
 * agent's new user turn.
 *
 * Outside a swarm run the trait is a no-op: {@see messages()} returns
 * {@see mergeRunContextMessages()} applied to an empty list (an empty array by
 * default), so the agent behaves exactly as it would without the trait.
 *
 * The trait owns {@see messages()} (a trait method cannot call `parent::`), so
 * it cannot be combined with laravel/ai's `RemembersConversations` — pick one,
 * or alias the conflicting method. To blend run-context messages with your own
 * history, override {@see mergeRunContextMessages()}; to change the role of the
 * rendered turns, override {@see runContextMessageRole()} or set
 * `swarm.memory.run_context_messages.role`.
 */
trait RemembersRunContext
{
    /**
     * @return iterable<int, Message>
     */
    public function messages(): iterable
    {
        $messages = Container::getInstance()
            ->make(RunContextMemoryReader::class)
            ->messages(
                $this instanceof Agent ? $this : null,
                $this->runContextMessageRole(),
            );

        return $this->mergeRunContextMessages($messages);
    }

    /**
     * The role assigned to each rendered run-context message. Defaults to the
     * configured `swarm.memory.run_context_messages.role` (Assistant). Override
     * to frame run context under a different role for this agent.
     */
    protected function runContextMessageRole(): MessageRole
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return MessageRole::Assistant;
        }

        $configured = $container->make('config')
            ->get('swarm.memory.run_context_messages.role', MessageRole::Assistant->value);

        return is_string($configured)
            ? (MessageRole::tryFrom($configured) ?? MessageRole::Assistant)
            : MessageRole::Assistant;
    }

    /**
     * Hook to combine the run-context messages with the agent's own history.
     * The default returns the run-context messages unchanged.
     *
     * @param  array<int, Message>  $runContextMessages
     * @return iterable<int, Message>
     */
    protected function mergeRunContextMessages(array $runContextMessages): iterable
    {
        return $runContextMessages;
    }
}
