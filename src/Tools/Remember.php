<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tools;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Memory\MemoryToolScopeResolver;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\SwarmMemoryKeys;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent tool for writing Swarm memory mid-prompt.
 *
 * Drop into any `laravel/ai` agent's `tools()` array. The model calls it with a
 * `key` and `value`, optionally naming a `scope` (default Run). The write flows
 * through {@see SwarmMemory::put()}, which is decorated by the
 * {@see RedactingMemoryStore}, so the
 * {@see MemoryCapturePolicy} redacts or
 * drops the entry at the write boundary exactly as it would for any other
 * write — the tool never bypasses capture.
 *
 * The scope id is never accepted from the model; it is resolved from the
 * ambient {@see ActiveRunContext} via
 * {@see MemoryToolScopeResolver}, so an agent cannot write into another run's,
 * swarm's, or conversation's memory. The Conversation scope is addressable only
 * when a conversation id is bound to the run via
 * {@see RunContext::withConversationId()};
 * without one, a Conversation-scoped write declines gracefully. Package-reserved keys (the `swarm:` prefix) are rejected so an
 * agent cannot overwrite framework-owned entries such as step outputs.
 *
 * Outside a swarm run (no active context) the tool degrades gracefully: it
 * reports that memory is unavailable instead of throwing.
 */
class Remember implements Tool
{
    public function __construct(
        protected readonly ?string $description = null,
    ) {}

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return 'remember';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return $this->description ?? <<<'TEXT'
        Save a value to shared memory so later agents in this run can read it
        with the recall tool. Provide a `key` and a `value`. `scope` defaults to
        "run" (memory for the current task); use "swarm" to share across the
        whole swarm. Values may be redacted by the application's capture policy.
        TEXT;
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $key = trim($request->string('key')->toString());

        if ($key === '') {
            return 'A memory key is required.';
        }

        if (str_starts_with($key, SwarmMemoryKeys::RESERVED_PREFIX)) {
            return 'Keys starting with ['.SwarmMemoryKeys::RESERVED_PREFIX.'] are reserved and cannot be written.';
        }

        $scope = $this->resolveScope($request->string('scope')->toString());

        if ($scope === null) {
            return 'Unknown memory scope. Use one of: run, swarm, agent, conversation.';
        }

        $resolved = $this->scopeResolver()->resolve($scope);

        if ($resolved === null) {
            // Distinguish "no run at all" from "in a run, but this scope has no
            // addressable id here" (conversation only when the run is bound to
            // one; agent only when the tool is bound to one). Reporting the run
            // as missing when it is active would wrongly tell the model memory
            // is unusable.
            if (ActiveRunContext::current() === null) {
                return 'Memory is not available outside an active swarm run.';
            }

            return 'The ['.$scope->value.'] scope is not addressable in this run.';
        }

        $this->memory()->put(
            $resolved->scope,
            $resolved->scopeId,
            $key,
            $request->has('value') ? $request['value'] : null,
            $this->writeMetadata(),
        );

        return 'Stored ['.$key.'] in '.$scope->value.' memory.';
    }

    /**
     * Attribution attached to every tool-driven write, so audit listeners on
     * {@see MemoryWritten} can tell a
     * model-initiated `Remember` apart from a framework write. The bound agent
     * class is included when the tool was constructed for a specific agent.
     *
     * @return array<string, mixed>
     */
    protected function writeMetadata(): array
    {
        $metadata = ['origin' => 'tool:remember'];

        if (($agent = $this->agent()) !== null) {
            $metadata['agent'] = $agent::class;
        }

        return $metadata;
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
                ->description('The memory key to write. Must not start with "swarm:" (reserved).')
                ->required(),
            'value' => $schema
                ->string()
                ->description('The value to store.')
                ->required(),
            'scope' => $schema
                ->string()
                ->enum(array_map(static fn (MemoryScope $case): string => $case->value, MemoryScope::cases()))
                ->description('Memory scope to write to. Defaults to "run".'),
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

    protected function scopeResolver(): MemoryToolScopeResolver
    {
        return new MemoryToolScopeResolver($this->agent());
    }

    /**
     * The agent the tool writes as, used only to address the Agent scope. Null
     * by default; {@see Remember} is scope-driven, not agent-bound.
     */
    protected function agent(): ?Agent
    {
        return null;
    }

    protected function memory(): SwarmMemory
    {
        return Container::getInstance()->make(SwarmMemory::class);
    }
}
