<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tools;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\AgentVisibleMemoryView;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\RunContextMemoryReader;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use ReflectionClass;
use Stringable;
use Throwable;

/**
 * Agent tool for reading Swarm memory mid-prompt.
 *
 * Drop into any `laravel/ai` agent's `tools()` array. The model calls it with a
 * single `key`, a `prefix`, or no addressing argument to list a whole `scope`.
 * Reads always flow through the active swarm's
 * {@see MemoryPropagationPolicy} via
 * {@see AgentVisibleMemoryView}, so the tool can only ever surface entries the
 * policy already permits this agent to see — it cannot leak from a scope the
 * policy withholds.
 *
 * The scope id is never accepted from the model. It is resolved from the
 * ambient {@see ActiveRunContext} (Run → run id, Swarm → swarm class), so an
 * agent cannot read another run's or swarm's memory by guessing an id.
 *
 * Outside a swarm run (no active context) the tool degrades gracefully: it
 * returns a short "memory is not available" string instead of throwing, so an
 * agent wired with the tool still works when invoked standalone.
 */
class Recall implements Tool
{
    public function __construct(
        protected readonly ?string $description = null,
    ) {}

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return 'recall';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return $this->description ?? <<<'TEXT'
        Read shared memory accumulated during this run. Pass a `key` to read one
        value, a `prefix` to read every key starting with it, or neither to list
        the whole `scope`. `scope` defaults to "run" (memory for the current
        task); use "swarm" for state shared across the whole swarm. Only memory
        the active visibility policy permits this agent to see is returned.
        TEXT;
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $scope = $this->resolveScope($request->string('scope')->toString());

        if ($scope === null) {
            return 'Unknown memory scope. Use one of: run, swarm, agent, conversation.';
        }

        $entries = $this->visibleEntries($scope);

        if ($entries === null) {
            return 'Memory is not available outside an active swarm run.';
        }

        $key = $this->trimmedOrNull($request->string('key')->toString());
        $prefix = $this->trimmedOrNull($request->string('prefix')->toString());

        if ($key !== null) {
            return $this->renderSingle($entries, $key);
        }

        if ($prefix !== null) {
            $entries = array_values(array_filter(
                $entries,
                static fn (MemoryEntry $entry): bool => str_starts_with($entry->key, $prefix),
            ));
        }

        return $this->renderList($entries);
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema
                ->string()
                ->description('The exact memory key to read. Omit to read by prefix or to list the scope.'),
            'prefix' => $schema
                ->string()
                ->description('Read every key that starts with this prefix. Ignored when `key` is given.'),
            'scope' => $schema
                ->string()
                ->enum(array_map(static fn (MemoryScope $case): string => $case->value, MemoryScope::cases()))
                ->description('Memory scope to read from. Defaults to "run".'),
        ];
    }

    /**
     * Resolve the requested scope name to a {@see MemoryScope}, defaulting to
     * the Run scope when the model omits it. Returns null for an unknown name.
     */
    protected function resolveScope(string $scope): ?MemoryScope
    {
        $scope = trim($scope);

        if ($scope === '') {
            return MemoryScope::Run;
        }

        return MemoryScope::tryFrom($scope);
    }

    /**
     * Gather the entries this agent is permitted to see in the requested scope,
     * filtered through the active swarm's propagation policy. Returns null when
     * there is no active run (the tool then reports graceful unavailability).
     *
     * @return array<int, MemoryEntry>|null
     */
    protected function visibleEntries(MemoryScope $scope): ?array
    {
        $record = ActiveRunContext::current();

        if ($record === null) {
            return null;
        }

        $swarm = $this->resolveSwarm($record->swarmClass);

        if ($swarm === null) {
            return null;
        }

        $view = Container::getInstance()->make(AgentVisibleMemoryView::class);

        $presented = $view->present($swarm, $record->context, $this->agent());

        return array_values(array_filter(
            $presented,
            static fn (MemoryEntry $entry): bool => $entry->scope === $scope,
        ));
    }

    /**
     * The agent the tool reads as, when the propagation policy keys on agent
     * identity. Null by default; {@see Recall} is scope-driven, not agent-bound.
     */
    protected function agent(): ?Agent
    {
        return null;
    }

    /**
     * @param  array<int, MemoryEntry>  $entries
     */
    protected function renderSingle(array $entries, string $key): string
    {
        foreach ($entries as $entry) {
            if ($entry->key === $key) {
                return $key.': '.$this->renderValue($entry->value);
            }
        }

        return 'No memory found for key ['.$key.'].';
    }

    /**
     * @param  array<int, MemoryEntry>  $entries
     */
    protected function renderList(array $entries): string
    {
        if ($entries === []) {
            return 'No memory found.';
        }

        $lines = [];

        foreach ($entries as $entry) {
            $lines[] = $entry->key.': '.$this->renderValue($entry->value);
        }

        return implode("\n", $lines);
    }

    protected function renderValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_null($value) => 'null',
            is_array($value) => $this->encodeArray($value),
            default => '[unreadable]',
        };
    }

    /**
     * @param  array<mixed>  $value
     */
    protected function encodeArray(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '[unreadable]';
        }
    }

    protected function trimmedOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Resolve a {@see Swarm} instance purely so {@see AgentVisibleMemoryView}
     * can read its class name and `#[PropagationPolicy]` attribute. The view
     * never touches swarm state, so a constructor-less instance suffices and
     * avoids depending on the swarm being container-resolvable. Mirrors
     * {@see RunContextMemoryReader}.
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
}
