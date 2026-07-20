<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Concerns\RemembersRunContext;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use ReflectionClass;
use Throwable;

/**
 * Renders the active swarm run's propagation-policy memory view as laravel/ai
 * {@see Message}s for an agent using the
 * {@see RemembersRunContext} trait.
 *
 * Reads the ambient {@see ActiveRunContext}; when none is set (the agent is
 * invoked outside a swarm) it returns an empty list, so the trait is a no-op.
 * Otherwise it rebuilds the agent-visible view via {@see AgentVisibleMemoryView}
 * — honouring the swarm's propagation policy and the canonical entry order the
 * snapshot also freezes — and maps each presented entry to a Message. Reading
 * through the view (never {@see SwarmMemory::all()}
 * directly) is what guarantees policy filtering and deterministic order.
 *
 * @internal
 */
final class RunContextMemoryReader
{
    public function __construct(
        protected AgentVisibleMemoryView $view,
    ) {}

    /**
     * @return array<int, Message>
     */
    public function messages(?Agent $agent, MessageRole|string $role = MessageRole::Assistant): array
    {
        $record = ActiveRunContext::current();

        if ($record === null) {
            return [];
        }

        $swarm = $this->resolveSwarm($record->swarmClass);

        if ($swarm === null) {
            return [];
        }

        $messages = [];

        foreach ($this->view->present($swarm, $record->context, $agent) as $entry) {
            $content = $this->renderContent($entry->key, $entry->value);

            if ($content === null) {
                continue;
            }

            $messages[] = new Message($role, $content);
        }

        return $messages;
    }

    /**
     * Resolve a {@see Swarm} instance purely so {@see AgentVisibleMemoryView}
     * can read its class name and `#[PropagationPolicy]` attribute. The view
     * never touches swarm state, so a constructor-less instance is sufficient
     * and avoids depending on the swarm being container-resolvable.
     */
    protected function resolveSwarm(string $swarmClass): ?Swarm
    {
        if (! is_a($swarmClass, Swarm::class, true)) {
            return null;
        }

        try {
            /** @var Swarm $swarm */
            $swarm = (new ReflectionClass($swarmClass))->newInstanceWithoutConstructor();

            return $swarm;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Serialize an entry value to message content, prefixed with its key for
     * legibility. Returns null for values that should not produce a turn
     * (null, objects, un-encodable arrays).
     */
    protected function renderContent(string $key, mixed $value): ?string
    {
        $rendered = match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => $this->encodeArray($value),
            default => null,
        };

        if ($rendered === null) {
            return null;
        }

        return $key.': '.$rendered;
    }

    /**
     * @param  array<mixed>  $value
     */
    protected function encodeArray(array $value): ?string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }
}
