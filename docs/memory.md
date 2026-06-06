# Swarm Memory

Swarm Memory is the first-class, scoped, snapshot-replayable memory subsystem shipped in v0.9.0. It gives agents and application code a place to read and write structured values that persist across steps, survive queue boundaries, and can be replayed deterministically from a frozen snapshot on a crash-resume.

The primary surface is the `SwarmMemory` contract, resolved from the container or via `app(SwarmMemory::class)`. The `RunContext` object — the envelope every swarm run carries — writes through to memory automatically, so you can start using memory without any wiring change.

---

## Scope hierarchy

Every memory entry lives in exactly one scope. The scope determines the lifetime and visibility of the entry, and pairs with a `scopeId` (the concrete run ID, conversation ID, agent class, or swarm class) to form the full address.

```
Swarm scope  ──── shared across all agents in a swarm class
  │
  └── Conversation scope  ──── shared across runs in the same conversation thread
        │
        └── Run scope  ──── bounded to a single swarm run
              │
              └── Agent scope  ──── per-agent-class persistent state
```

| Scope | `MemoryScope` case | `scopeId` | Lifetime |
| --- | --- | --- | --- |
| **Run** | `MemoryScope::Run` | The `runId` string | Cleared with the run |
| **Conversation** | `MemoryScope::Conversation` | A conversation thread ID | Shared across runs |
| **Agent** | `MemoryScope::Agent` | The agent class (FQCN) | Per-agent persistent state |
| **Swarm** | `MemoryScope::Swarm` | The swarm class (FQCN) | Shared across all agents in a swarm |

**v0.9.0 note:** The snapshot mechanism (see [Snapshot mechanism](#snapshot-mechanism) below) captures only `MemoryScope::Run` entries for each agent invocation. Reads against the other three scopes during replay fall through to the live store and fire a `MemoryScopeOutOfSnapshot` event.

---

## Reading and writing

Resolve `SwarmMemory` from the container and call the value-shaped methods:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

$memory = app(SwarmMemory::class);

// Write a value — inserts or updates by (scope, scopeId, key)
$entry = $memory->put(
    MemoryScope::Run,
    $runId,
    'draft_approved',
    true,
);

// Read a value — returns the raw value or null when absent
$approved = $memory->get(MemoryScope::Run, $runId, 'draft_approved');

// Read the full entry when you need metadata or timestamps
$entry = $memory->entry(MemoryScope::Run, $runId, 'draft_approved');
$entry?->createdAt;   // CarbonImmutable
$entry?->updatedAt;   // CarbonImmutable
$entry?->metadata;    // array<string, mixed>

// Delete a single entry — returns true when a row was removed
$existed = $memory->forget(MemoryScope::Run, $runId, 'draft_approved');

// Retrieve every entry under a (scope, scopeId)
$entries = $memory->all(MemoryScope::Run, $runId);
// => array<int, MemoryEntry>
```

All four methods are covered by the `SwarmMemory` contract. Custom drivers and test doubles can implement the contract without touching any other class.

> **Reserved key prefix.** Keys beginning with `swarm:` are reserved for the package (e.g. `swarm:step.{n}.output`). The propagation policies treat them specially — `DefaultPropagationPolicy` hides them; `ConversationPropagationPolicy` surfaces them — so avoid writing your own keys under `swarm:` from application code.

### Scope-bounded query example

Reading all Run-scoped entries for a completed run:

```php
$entries = app(SwarmMemory::class)->all(MemoryScope::Run, $response->context->runId);

foreach ($entries as $entry) {
    echo "{$entry->key}: " . json_encode($entry->value) . "\n";
}
```

Reading a per-agent persistent value (knowledge that should survive across runs):

```php
$tone = app(SwarmMemory::class)->get(
    MemoryScope::Agent,
    WriterAgent::class,
    'preferred_tone',
);
```

---

## Value objects

### `MemoryEntry`

`MemoryEntry` is the immutable value object returned by `put()`, `entry()`, and `all()`. It carries the full address plus the persisted value and metadata.

| Property | Type | Description |
| --- | --- | --- |
| `scope` | `MemoryScope` | The addressing scope |
| `scopeId` | `string` | The concrete ID within the scope |
| `key` | `string` | The entry name |
| `value` | `mixed` | Plain data: string, int, float, bool, null, or nested arrays of those types |
| `metadata` | `array<string, mixed>` | Implementation annotations (capture policy outcome, source, etc.) |
| `createdAt` | `?CarbonImmutable` | Populated by the store on first write |
| `updatedAt` | `?CarbonImmutable` | Populated by the store on every write |

`value` must be plain data. The store normalizes it before persistence; non-serializable values (objects, closures, resources) are rejected.

### `MemoryScope`

```php
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

MemoryScope::Run          // 'run'
MemoryScope::Conversation // 'conversation'
MemoryScope::Agent        // 'agent'
MemoryScope::Swarm        // 'swarm'
```

---

## Propagation policy

When a runner invokes a worker agent, a **propagation policy** decides which memory entries that agent sees. The policy runs at the snapshot chokepoint — the same point where the agent-visible view is frozen — so the persisted `MemorySnapshot` mirrors exactly what the policy permitted. Every runner (sequential, parallel, hierarchical, and the durable/queued path) consults it.

The contract has two methods. `scopes()` declares *what to load* — the runner gathers candidates from exactly those scopes and no others, so a policy never pays to read a scope it ignores. `present()` is the *what to show* filter: drop, reorder, or narrow by key or age within the gathered candidates.

```php
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

final class SharedSwarmStatePolicy implements MemoryPropagationPolicy
{
    /**
     * Load Run- and Swarm-scoped candidates; ignore everything else.
     *
     * @return array<int, MemoryScope>
     */
    public function scopes(): array
    {
        return [MemoryScope::Run, MemoryScope::Swarm];
    }

    /**
     * @param  array<int, MemoryEntry>  $candidateEntries
     * @return array<int, MemoryEntry>
     */
    public function present(array $candidateEntries, RunContext $context, ?Agent $agent): array
    {
        // Already scoped to Run + Swarm by scopes(); pass through unchanged.
        return $candidateEntries;
    }
}
```

`present()` receives the candidates gathered from the scopes `scopes()` declared, the live `RunContext`, and the target `Agent` (null on the durable and hierarchical-parallel paths, where only the agent class is known at freeze time). It returns the ordered subset the agent may see. Implementations must not fabricate or mutate entries — they may only drop and reorder. Note: the `Agent` scope is gathered only when the concrete agent instance is known, and the `Conversation` scope is gathered only when the run is bound to a conversation id (see [Conversation-scoped memory](#conversation-scoped-memory)) — declaring either scope on a run that cannot address it is a no-op.

### Default behavior

The default `DefaultPropagationPolicy` presents the **Run-scoped view only**, which is exactly what runners froze and agents saw before v0.10. Unmodified swarms are unaffected: no package code writes to the Conversation, Agent, or Swarm scopes during a run, so the default view is byte-identical to pre-v0.10 behavior.

### Choosing a policy

Bind a global default in a service provider or via config:

```php
// config/swarm.php
'memory' => [
    'propagation_policy' => \App\Memory\SharedSwarmStatePolicy::class,
],
```

```bash
# or via env
SWARM_MEMORY_PROPAGATION_POLICY="App\\Memory\\SharedSwarmStatePolicy"
```

Override for a single swarm with the `#[PropagationPolicy]` attribute (the class is resolved through the container, so it may declare its own dependencies):

```php
use BuiltByBerry\LaravelSwarm\Attributes\PropagationPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

#[PropagationPolicy(SharedSwarmStatePolicy::class)]
class ResearchSwarm implements Swarm
{
    // ...
}
```

The attribute takes precedence over the config-bound default. Widening the view is a **semantic** change — downstream agents may see more memory than they did before — but it is never an API change, and the default keeps the v0.9 behavior.

---

## Conversation-scoped memory

`MemoryScope::Conversation` is the scope shared across every run in the same conversation thread — longer-lived than a run, narrower than the whole swarm class. You can store and query it directly from the start:

```php
$memory->put(MemoryScope::Conversation, 'conv-42', 'preference', 'concise');
$memory->all(MemoryScope::Conversation, 'conv-42');
```

What needs a handle is surfacing it *at runtime*: the snapshot view and the `Recall`/`Remember` tools resolve the scope id from the active run, not from the model, so they need to know which conversation the run belongs to. Bind it on the `RunContext` with `withConversationId()`:

```php
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$swarm->run(
    RunContext::from('Summarise the thread so far', $runId)
        ->withConversationId('conv-42'),
);
```

Once bound, the id threads through every execution path — sync, queued, durable, and the parallel/hierarchical branches — because it travels in the run's metadata, which all of those paths carry wholesale. With a conversation id in hand:

- **`AgentVisibleMemoryView`** gathers `Conversation`-scoped entries under that id, so a `MemoryPropagationPolicy` that declares the scope (e.g. `[MemoryScope::Run, MemoryScope::Conversation]`) surfaces the conversation's memory to the agent. Two runs in different conversations never see each other's entries — the scope id isolates them.
- **`Recall`** can read the conversation's entries (subject to the propagation policy), and **`Remember`** can write to `conversation` scope. Without a bound conversation id the scope stays unaddressable: `Remember` declines with a clear message and `Recall` simply finds nothing there.

Deriving the conversation id from request or session state is application wiring — Swarm does not guess it. Set it explicitly per run as shown above.

> **Multi-tenant note.** Conversation scope is addressed by the **app-supplied
> conversation id**, not by anything Swarm derives. If your ids are not unique
> across tenants — per-tenant sequential numbers, raw chat-thread ids — a run in
> tenant B bound to a reused id surfaces (and lets an agent `Remember` into)
> tenant A's conversation memory. The propagation policy gates *which scopes* an
> agent sees, not *which tenant's* conversation an id resolves to. In a
> multi-tenant app, either namespace conversation ids per tenant (e.g.
> `"{tenant}:{thread}"`) or enforce the boundary in a tenant-aware
> `MemoryPropagationPolicy`. See the [tenant-isolated memory](memory-recipes.md#tenant-isolated-memory) recipe.

> **Dump expansion is separate.** Binding a conversation id surfaces memory to agents, but Swarm still records no queryable link from a conversation back to its runs in its own tables. To make `swarm:memory:dump <conversation-id>` expand a conversation into its constituent runs, bind a [`ConversationRunResolver`](#exporting-a-full-run-with-swarmmemorydump) that knows your app's conversation/run topology; the bundled `NullConversationRunResolver` resolves to an empty run list.

---

## Reading run memory inside an agent with `RemembersRunContext`

The propagation policy decides what an agent is *allowed* to see, and the runner freezes that view into the snapshot. But a plain `laravel/ai` agent is still invoked with only the prompt string — it never sees the memory view as conversation. The opt-in `BuiltByBerry\LaravelSwarm\Concerns\RemembersRunContext` trait bridges that gap.

Add the trait to an agent that also implements `Laravel\Ai\Contracts\Conversational`:

```php
use BuiltByBerry\LaravelSwarm\Concerns\RemembersRunContext;
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

class Editor implements Agent, Conversational
{
    use Promptable;
    use RemembersRunContext;

    public function instructions(): string
    {
        return 'You refine the draft using the shared run context.';
    }
}
```

When the agent runs inside a swarm, `messages()` returns the active run's propagation-policy view rendered as `Laravel\Ai\Messages\Message[]`. `laravel/ai` prepends those messages before the new user turn, so the model sees a real conversation. Each presented entry becomes one message, prefixed with its key (`"{key}: {value}"`); arrays are JSON-encoded, `null` values are skipped, and messages preserve the policy's canonical order. **Outside a swarm run the trait is a no-op** — `messages()` returns an empty list (subject to the merge hook below), so the agent behaves exactly as it would without the trait.

Because the view is built through the same `AgentVisibleMemoryView` the runners use, `messages()` is filtered by the swarm's `MemoryPropagationPolicy` and redacted by the `MemoryCapturePolicy` — it can never surface more than [`swarm:memory:inspect`](#exporting-a-full-run-with-swarmmemorydump) shows.

### Per-step output capture and the conversation transcript

From v0.10.0 the runners can persist **each step's output** to Run scope under the reserved key `swarm:step.{n}.output` (`n` is the step index). This is what lets a sequential pipeline render a true turn-by-turn transcript with no application wiring. It is **disabled by default** — enabling it persists each agent's output to your memory store — and flows through the same `MemoryCapturePolicy` → snapshot → retention path as any Run-scoped write. Enable it with:

```php
// config/swarm.php
'memory' => [
    'capture_step_output' => env('SWARM_MEMORY_CAPTURE_STEP_OUTPUT', false),
],
```

Before enabling, decide on two governance levers, since this expands what you store at rest:

- **Retention** — Run-scoped rows are pruned by [`swarm:memory:purge`](#retention) per `swarm.memory.retention.days.run` (default `null` = kept indefinitely). Set a window if you don't want step outputs retained forever; growth is managed here, not by truncation.
- **Redaction** — a [`MemoryCapturePolicy`](#capture-policy-write-time-redaction) can redact or skip these keys at the write boundary if outputs may carry PII.

Captured values are stored **full-fidelity**: unlike artifacts, history, and the final output, they are **not** truncated by `swarm.limits.max_output_bytes`, so the audit record stays complete.

These keys are **reserved**. `DefaultPropagationPolicy` excludes them, so capturing step output never changes what a non-trait agent sees — the default view stays byte-for-byte what it was before. To surface them as a conversation, opt into `ConversationPropagationPolicy`, the policy designed to pair with `RemembersRunContext`:

```php
use BuiltByBerry\LaravelSwarm\Attributes\PropagationPolicy;
use BuiltByBerry\LaravelSwarm\Memory\ConversationPropagationPolicy;

#[PropagationPolicy(ConversationPropagationPolicy::class)]
class WriterSwarm implements Swarm { /* ... */ }
// or globally: 'propagation_policy' => ConversationPropagationPolicy::class
```

It presents the step outputs as an ordered transcript. By default it shows **only** the transcript; set `include_run_memory` to also append the rest of the Run-scoped view (`last_output`, your own keys):

```php
'memory' => [
    'conversation_view' => [
        'include_run_memory' => env('SWARM_MEMORY_CONVERSATION_INCLUDE_RUN_MEMORY', false),
    ],
],
```

You can still write your own Run-scoped keys for the agent to see — under either policy:

```php
// In a tool, guardrail, or an earlier step:
Swarm::memory()->put(MemoryScope::Run, $runId, 'brief', 'Ship the v2 launch post.');
// or, via the RunContext array bridge:
$context['brief'] = 'Ship the v2 launch post.';
```

**Where step outputs are visible.** A snapshot freezes the *propagation-policy view*, taken **before** each agent runs. Two consequences worth internalizing:

- Under `DefaultPropagationPolicy` the step keys are excluded, so `swarm:memory:inspect` does **not** show them. They are always in raw Run memory, so `swarm:memory:dump`, `Swarm::memory()->get(...)`, and retention/purge all see them. For audit evidence of step outputs, reach for [`swarm:memory:dump`](#exporting-a-full-run-with-swarmmemorydump), not `inspect`.
- A step never sees its own (not-yet-produced) output, only prior steps': step `k`'s view holds `swarm:step.0..k-1.output`. The final step's output is therefore in no in-run view — it lives in raw memory like every other.

> `StaticHierarchicalStreamRunner` freezes no snapshot (a pre-existing v0.9 gap, [#159](https://github.com/builtbyberry/laravel-swarm/issues/159)), but it still records steps, so per-step capture to raw memory works there like everywhere else.

### Configuring the message role

Rendered entries use the `assistant` role by default (they are prior context, not the new user turn). Change it globally:

```php
// config/swarm.php
'memory' => [
    'run_context_messages' => [
        'role' => env('SWARM_MEMORY_RUN_CONTEXT_ROLE', 'assistant'), // assistant | user | tool_result
    ],
],
```

…or per agent by overriding `runContextMessageRole(): MessageRole`.

### Combining with the agent's own history

The trait *owns* `messages()`, so it cannot be combined with `laravel/ai`'s `RemembersConversations` (both define `messages()` — PHP rejects the conflict). To blend the run-context messages with history of your own, override `mergeRunContextMessages()`:

```php
protected function mergeRunContextMessages(array $runContextMessages): iterable
{
    return [...$this->priorTurns(), ...$runContextMessages];
}
```

The runner publishes the active run around every agent invocation across all four runners (sequential, parallel, hierarchical/static-hierarchical, durable) and both streaming paths, via the internal `ActiveRunContext` handle — a process-local stack holding the live `RunContext`, cleared in a `finally`. It is deliberately *not* backed by `Illuminate\Support\Facades\Context`: the run's input and memory never enter log records or queued-job payloads, and there is no observable change for agents that don't use the trait. Cross-process workers re-establish the handle explicitly from the forwarded run context, so on a real multi-process concurrency driver run-memory visibility inside a parallel worker requires a process-shared `MemoryStore` (database or shared cache) — the same constraint that applies to snapshots.

---

## Recall and Remember tools

Where `RemembersRunContext` injects the run's memory as conversation *before* the
agent thinks, the **`Recall` and `Remember` tools** let the agent read and write
memory *while* it thinks — as ordinary `laravel/ai` tool calls mid-prompt. Both
implement `Laravel\Ai\Contracts\Tool`, so they drop into any agent's `tools()`
array with no Swarm-specific code in the agent.

```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use BuiltByBerry\LaravelSwarm\Tools\Recall;
use BuiltByBerry\LaravelSwarm\Tools\Remember;

class Researcher implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Research the topic. Use the remember tool to save findings for '
            .'later agents, and the recall tool to read what earlier agents found.';
    }

    public function tools(): iterable
    {
        return [new Recall, new Remember];
    }
}
```

- **`Recall`** reads memory. The model calls it with a single `key`, a `prefix`
  (every key starting with it), or neither (the whole `scope`). `scope` defaults
  to `run`.
- **`Remember`** writes memory. The model calls it with a `key` and a `value`,
  optionally naming a `scope` (default `run`).

### Scopes and addressing

Both tools take a `scope` *name* from the model but never a scope *id* — the id
is framework-owned and resolved from the active run, so an agent can never
address another run's, swarm's, agent's, or conversation's memory by guessing an
id:

| Scope          | Resolves to                          |
| -------------- | ------------------------------------ |
| `run` (default) | the active run id                   |
| `swarm`        | the active swarm class               |
| `agent`        | only when the tool is bound to a specific agent (see below) |
| `conversation` | the run's bound conversation id, when set (see [Conversation-scoped memory](#conversation-scoped-memory)); otherwise declined gracefully |

`run` is the safe default: memory scoped to the current task, cleared with it.
Use `swarm` for state shared across the whole swarm class.

> **Multi-tenant note.** A `swarm`-scope write is addressed by the swarm
> *class*, so it is shared across **every run — and every tenant — of that swarm
> class**, not just the current task. An agent that `Remember`s into `swarm`
> scope can therefore influence what later runs `Recall` (subject to their
> propagation policy). The capture policy gates *what value* is written, not
> *which* tenant or agent may write it. If you enable `remember` in a
> multi-tenant app, either keep agents to `run` scope, partition tenants into
> distinct swarm classes, or enforce the boundary in your capture policy.

### Policy interaction

Neither tool bypasses Swarm's memory policies:

- **`Recall` respects the propagation policy.** It reads through the same
  agent-visible view the runners freeze into snapshots, so it can only ever
  surface entries the active swarm's `MemoryPropagationPolicy` already permits
  this agent to see. Under the default policy that is the Run-scoped view;
  entries the policy withholds (other scopes, filtered keys) are invisible to
  `Recall` exactly as they are to the agent's input.
- **`Remember` respects the capture policy.** Writes go through
  `SwarmMemory::put()`, which is decorated by the `RedactingMemoryStore`, so the
  `MemoryCapturePolicy` redacts (`[redacted]`) or drops (`Skip`) the entry at the
  write boundary — the same enforcement any other write gets. PII an agent tries
  to persist never enters memory if your policy redacts it.

`Remember` also rejects the package-reserved `swarm:` key prefix, so an agent
cannot overwrite framework-owned entries such as step outputs.

### Behaviour inside and outside a swarm

Both tools work inside all four topologies (sequential, parallel,
hierarchical/static-hierarchical) and across nested runs — each run frame
addresses its own scope. Invoked **outside** a swarm run (no active run), they
degrade gracefully: instead of throwing, they return a short "memory is not
available" string, so an agent wired with the tools still works standalone.

### Memory tools with streaming

`Recall` and `Remember` work transparently inside `$agent->stream(...)`. Because
both implement `Laravel\Ai\Contracts\Tool`, `laravel/ai` already handles their
invocation during a streamed turn — the package adds no streaming-specific tool
code. When the model calls a memory tool mid-stream:

- The tool call and its result appear in the `StreamableSwarmResponse` as
  ordinary `swarm_tool_call` / `swarm_tool_result` events, in order, exactly as
  any other `laravel/ai` tool would surface. The memory side-effect (a
  `Remember` write, a `Recall` read) happens at the point of the call, before
  the result event is yielded.
- The sequential stream runner publishes the active run *before* it invokes the
  final agent's `stream()`, so a memory tool resolves its scope id from the
  ambient run identically to a `prompt()` run. A streamed `Recall` therefore
  sees what earlier steps wrote, and a streamed `Remember` write is visible to
  later steps.

```php
$stream = $swarm->stream('Summarise what the team found.');

foreach ($stream as $event) {
    // swarm_tool_call → swarm_tool_result for each Recall/Remember the model made,
    // interleaved with the usual text deltas.
}
```

**Snapshot capture and replay.** The [snapshot mechanism](#snapshot-mechanism)
captures both the tool-call input and the tool result for every memory-tool call
made inside a streamed run. The runner holds each `ToolCall` until its matching
`ToolResult` arrives, then appends a single paired entry to the step snapshot, so
the snapshot row's write count stays proportional to tool *results*, not raw
stream events. A call left unmatched by stream end is still recorded with a null
result so replay can detect a partial run.

Combined with [persisted stream replay](../docs/streaming.md), a streamed run
that used the memory tools replays byte-identically: re-running
`SwarmHistory::replay($runId)` yields the same ordered tool-call and tool-result
events the original stream produced.

### Optional default-on registration

Rather than wiring the tools into every agent by hand, add the
`HasSwarmMemoryTools` concern and merge `swarmMemoryTools()` into `tools()`:

```php
use BuiltByBerry\LaravelSwarm\Concerns\HasSwarmMemoryTools;

class Researcher implements Agent, HasTools
{
    use HasSwarmMemoryTools, Promptable;

    public function tools(): iterable
    {
        return [...$this->swarmMemoryTools(), new MyOtherTool];
    }
}
```

`swarmMemoryTools()` returns the tools only when `swarm.memory.tools.enabled` is
true, so adding the trait is inert until you opt in app-wide:

```php
// config/swarm.php — disabled by default; granting an LLM read/write access to
// shared run memory is an explicit decision. Review your propagation and capture
// policies first.
'memory' => [
    'tools' => [
        'enabled'  => env('SWARM_MEMORY_TOOLS_ENABLED', false),
        'recall'   => env('SWARM_MEMORY_TOOLS_RECALL', true),
        'remember' => env('SWARM_MEMORY_TOOLS_REMEMBER', true),
    ],
],
```

The `recall` / `remember` toggles enable each tool individually. The tool
classes are resolved from the container, so you can bind a subclass — for
example to override a tool's `description()`, or to bind it to a specific agent
so the `agent` scope resolves to that agent's class.

For worked, copy-paste patterns built on these hooks — per-user and tenant-scoped
recall, a policy-enforced custom `Recall`, recall + redact, and sub-agent memory
continuity — see [Memory recipes](memory-recipes.md).

---

## Capture policy (write-time redaction)

Where the propagation policy decides what an agent *reads*, the **capture policy** decides what gets *written*. `MemoryCapturePolicy` is consulted at the write boundary and returns a `CaptureDecision` per `(scope, key)`:

- **`Full`** — persist the value unchanged (the default for every write).
- **`Redact`** — persist the entry with scalar values replaced by the `SwarmCapture::REDACTED` sentinel (`'[redacted]'`), preserving array structure and keys so the entry stays addressable. This is the same sentinel the audit capture path uses.
- **`Skip`** — drop the entry entirely: no row is written and no `MemoryWritten` event fires. Skip suppresses *this* write only — any pre-existing entry at the address is left untouched (it is not deleted).

This is the write-side counterpart to the audit `CapturePolicy` (`swarm.capture.*`): redacting here keeps PII out of memory in the first place, so it never reaches a frozen `MemorySnapshot`. Like the audit policy, a capture policy **never receives the value** — only the scope and key — so a decision cannot couple to payload shape or leak unredacted data.

Enforcement lives in the `RedactingMemoryStore` decorator the container wraps around your memory driver (via `$app->extend(MemoryStore::class, …)`), so **every** write flows through one chokepoint — including a custom or companion driver you bind yourself. (Bind it with `bind()`/`singleton()`, not `Container::instance()`, so the decorator still wraps it.) Reads return already-redacted values, so the propagation view and frozen snapshots inherit redaction with no extra work.

> **Scope.** Redaction applies at the persistence boundary and covers the entry **value** only — not the entry **`metadata`** (which carries functional annotations like `source`/`usage`) and not the **key** (keys are addressing; redacting them would break `get`/`all`). Don't put PII in memory metadata or keys. A run's own in-process `RunContext` also still holds the raw value it just wrote until the run ends; the policy governs what is *persisted*, snapshotted, and visible to other agents.

Each non-`Full` decision is observable: a `Redact` write dispatches `MemoryRedacted` (alongside the usual `MemoryWritten`), and a `Skip` dispatches `MemoryWriteSkipped`. Both carry only the entry address (`scope`, `scopeId`, `key`), never the value — subscribe to them for an audit trail of capture-policy actions.

The default `DefaultMemoryCapturePolicy` returns `Full` for everything, preserving pre-v0.10 behavior. Bind your own globally:

```php
// config/swarm.php
'memory' => [
    'capture_policy' => \App\Memory\RedactSsnPolicy::class,
],
```

```bash
# or via env
SWARM_MEMORY_CAPTURE_POLICY="App\\Memory\\RedactSsnPolicy"
```

```php
use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

final class RedactSsnPolicy implements MemoryCapturePolicy
{
    public function memory(MemoryScope $scope, string $key, ?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return in_array($key, ['ssn', 'card_number'], true)
            ? CaptureDecision::Redact
            : CaptureDecision::Full;
    }
}
```

In tests, `SwarmFake::interceptMemoryCapturePolicy()` installs a `RecordingMemoryCapturePolicy` that records every decision (and optionally wraps a delegate that drives them), so you can assert what the store saw.

---

## Store drivers

The `MemoryStore` contract is the low-level storage driver. `SwarmMemory` delegates persistence to a bound `MemoryStore` implementation.

**`DatabaseMemoryStore`** (default) — persists entries to the `swarm_memories` table introduced in v0.9.0. Survives process restarts, queue boundaries, and durable checkpoints. The recommended driver for any application that uses `queue()` or `dispatchDurable()`.

**`CacheMemoryStore`** — persists entries to the Laravel cache. Suitable for synchronous `prompt()` runs in environments without a database; entries are subject to the cache TTL and are not durable.

### Swapping drivers

Bind a different implementation in a service provider:

```php
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use App\Memory\RedisMemoryStore;

$this->app->bind(MemoryStore::class, RedisMemoryStore::class);
```

Custom drivers must implement `MemoryStore` and dispatch the [memory lifecycle events](#lifecycle-events) from their `put()`, `get()`, and `forget()` methods. The bundled drivers dispatch events unconditionally — see the dispatch-layer note in `DefaultSwarmMemory` for the rationale.

### Installing the memory tables

Run `php artisan swarm:install:memory` (v0.9.0+) to publish and run the `swarm_memories` and `swarm_memory_snapshots` migrations. The command follows the same sentinel-marker pattern as the other sub-installers and is safe to re-run.

---

## Lifecycle events

Swarm Memory dispatches five events through Laravel's event system. Register listeners in `EventServiceProvider` or in a service provider closure.

| Event | When | Key properties |
| --- | --- | --- |
| `MemoryWritten` | After a successful `put()` | `scope`, `scopeId`, `key`, `metadata` |
| `MemoryRead` | After every `get()`, hit or miss | `scope`, `scopeId`, `key` |
| `MemoryForgotten` | After every `forget()` | `scope`, `scopeId`, `key`, `existed` |
| `MemorySnapshotted` | After a per-step snapshot is captured | `runId`, `stepIndex`, `snapshotId` |
| `MemoryScopeOutOfSnapshot` | During replay when a read targets a non-Run scope | `runId`, `stepIndex`, `scope`, `scopeId`, `key`, `operation` |

Events are dispatched at the **store layer**, not from the `SwarmMemory` facade. Custom `MemoryStore` drivers must dispatch them from their own `put()`, `get()`, and `forget()` implementations to keep the listener contract uniform.

`MemoryRead` does not expose the entry value. Listeners that need the value should re-read through the store under their own access controls — this keeps the event surface compatible with [capture-policy redaction](#capture-policy-write-time-redaction), which can redact or drop the value at write.

### Listener examples

**Audit trail — log every Run-scoped write:**

```php
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

Event::listen(MemoryWritten::class, function (MemoryWritten $event): void {
    if ($event->scope !== MemoryScope::Run) {
        return;
    }

    logger()->info('Memory written', [
        'scope'    => $event->scope->value,
        'scope_id' => $event->scopeId,
        'key'      => $event->key,
    ]);
});
```

**Compliance — hard-fail on out-of-snapshot cross-scope reads during replay:**

```php
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryScopeOutOfSnapshot;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

Event::listen(MemoryScopeOutOfSnapshot::class, function (MemoryScopeOutOfSnapshot $event): void {
    throw new SwarmException(
        "Replay determinism violation: {$event->operation} on {$event->scope->value} scope "
        . "at step {$event->stepIndex} of run {$event->runId}."
    );
});
```

Without this listener the event fires silently and the read falls through to the live store — pragmatic enough not to break agents that read shared knowledge, but no persistent record is created. Stricter compliance postures should wire this listener from day one; there is no passive record to fall back on.

**Monitoring — track forget with deletion audit:**

```php
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryForgotten;

Event::listen(MemoryForgotten::class, function (MemoryForgotten $event): void {
    if (! $event->existed) {
        return; // no-op probe — ignore for audit purposes
    }

    logger()->warning('Memory entry deleted', [
        'scope'    => $event->scope->value,
        'scope_id' => $event->scopeId,
        'key'      => $event->key,
    ]);
});
```

---

## Snapshot mechanism

Before every agent invocation, each runner (sequential, parallel, hierarchical, and durable branch) freezes the agent-visible memory view by calling `SnapshotsMemory::snapshot()`. The result — a `MemorySnapshot` — is persisted to the `swarm_memory_snapshots` table keyed by `(run_id, step_index)`.

The snapshot captures:

- **`entries`** — every `MemoryScope::Run` entry visible at the moment of invocation, byte-identical to what the agent saw.
- **`toolCalls`** — input/output pairs for every tool the agent called during the invocation. Appended in real time by the runner after each tool call completes.

### `MemorySnapshot` value object

| Property | Type | Description |
| --- | --- | --- |
| `runId` | `string` | The run the snapshot was taken for |
| `stepIndex` | `int` | Zero-based step index |
| `entries` | `array` | Plain-data entry rows as recorded |
| `toolCalls` | `array` | Tool input/output pairs recorded during the invocation |
| `frozen` | `bool` | `true` on snapshots loaded from persistence; `false` on in-flight writes |

**Inspecting a snapshot** from PHP:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;

$snapshot = app(SnapshotsMemory::class)->find($runId, $stepIndex);

if ($snapshot !== null) {
    foreach ($snapshot->entries as $row) {
        echo "{$row['key']}: " . json_encode($row['value']) . "\n";
    }

    foreach ($snapshot->toolCalls as $call) {
        echo "Tool: {$call['name']}, Result: " . json_encode($call['result']) . "\n";
    }
}
```

**Inspecting a snapshot** from the CLI with `swarm:memory:inspect`:

```bash
# List every snapshot recorded for a run (one row per step).
php artisan swarm:memory:inspect r-abc123

# Expand the frozen view for a single step — entries and tool calls.
php artisan swarm:memory:inspect r-abc123 --step=0

# JSON output for pipelines, audit evidence, and jq.
php artisan swarm:memory:inspect r-abc123 --step=0 --format=json

# Filter the entries view to a single scope. Under the default propagation
# policy a snapshot carries MemoryScope::Run only, so non-Run scopes return
# empty entries unless a custom policy presented that scope.
php artisan swarm:memory:inspect r-abc123 --step=0 --scope=run
```

The command reads frozen snapshots through the `SnapshotsMemory` contract and works uniformly across all four runners (sequential, parallel, hierarchical, durable branch). Pair it with `swarm:memory:dump` for full-run exports. See the [compliance audit guide](compliance-audit.md) for the broader operator workflow.

### Exporting a full run with `swarm:memory:dump`

Where `swarm:memory:inspect` is the interactive view of one run's snapshots,
`swarm:memory:dump` produces a **stable, machine-readable export** of a run's
complete memory + snapshot trail — for audit packets, legal/DSAR handoff, and
third-party debugging where raw DB access is not an option. It is read-only and
never mutates memory.

```bash
# Export a run as a pretty-printed JSON envelope (snapshot references only).
php artisan swarm:memory:dump 9b2c0e7a-... --format=json

# Embed each snapshot's full entries + tool calls.
php artisan swarm:memory:dump 9b2c0e7a-... --include-snapshots

# Stream one JSON object per line for large runs and jq pipelines.
php artisan swarm:memory:dump 9b2c0e7a-... --format=ndjson

# Write the export to a file instead of stdout.
php artisan swarm:memory:dump 9b2c0e7a-... --include-snapshots --output=/tmp/run.json
```

A run id and a conversation id are both bare UUIDs, so the command resolves the
subject by probe — `swarm_run_histories` first, then Conversation-scoped
`swarm_memories`. An id that matches **both** is refused; pass
`--as=run|conversation` to force the interpretation (and to script it
deterministically).

**Stable envelope schema** (`schema_version: "1.0"`). By default snapshots are
references only; `--include-snapshots` adds `entries` + `tool_calls` to each.

```jsonc
{
  "ok": true,
  "schema_version": "1.0",
  "subject_type": "run",            // "run" | "conversation"
  "subject_id": "9b2c0e7a-...",
  "generated_at": "2026-06-01T12:00:00+00:00",
  "include_snapshots": false,
  "entry_count": 2,
  "snapshot_count": 1,
  "scopes_included": ["run"],       // scopes the top-level "entries" cover
  "entries": [
    {
      "scope": "run", "scope_id": "9b2c0e7a-...", "key": "goal",
      "value": "ship it", "metadata": {},
      "created_at": "2026-06-01T11:59:00+00:00",
      "updated_at": "2026-06-01T11:59:00+00:00"
    }
  ],
  "snapshots": [
    {
      "run_id": "9b2c0e7a-...", "step_index": 0,
      "recorded_at": "2026-06-01T11:59:30+00:00",
      "updated_at": "2026-06-01T11:59:30+00:00",
      "entry_count": 1, "tool_call_count": 1
      // with --include-snapshots: + "entries": [...], "tool_calls": [...]
    }
  ]
}
```

In **NDJSON** mode the same data streams as one object per line: a
`{"record":"header", ...}` line carrying the envelope metadata, then one
`{"record":"entry", ...}` per memory entry, then one `{"record":"snapshot", ...}`
per snapshot.

**Scope boundary.** A run export carries only `Run`-scoped entries. `Agent`- and
`Swarm`-scoped memory key on an agent or swarm id, not a run id, so they cannot
be filtered by run and are not included. The `scopes_included` field declares
exactly what the top-level `entries` array covers (`["run"]` for a run;
`["conversation"]` or `["conversation", "run"]` for a conversation, depending on
whether a resolver expanded it). **Read `scopes_included` before certifying a run
export as a complete GDPR Art. 15 subject-access response** — confirm the
subject's data lives in `Run` scope.

**Conversation exports.** Given a conversation id, the dump carries that
conversation's Conversation-scoped entries. Swarm records no link between a run
and a conversation in v0.10 (the runtime exposes no conversation handle), so
run expansion is delegated to a bindable
`BuiltByBerry\LaravelSwarm\Contracts\ConversationRunResolver`. The bundled
`NullConversationRunResolver` resolves to no runs, and the envelope says so —
`runs_expanded: false`, the resolver class, and any `skipped_runs` — so a
non-expanded conversation export is never mistaken for a complete one. Bind your
own resolver (your application knows its conversation/run topology) to expand a
conversation into its runs and fold each run's Run-scoped entries + snapshots
into the same envelope:

```php
$this->app->singleton(
    \BuiltByBerry\LaravelSwarm\Contracts\ConversationRunResolver::class,
    \App\Swarm\AppConversationRunResolver::class,
);
```

Like `swarm:memory:inspect`, dump requires `swarm.persistence.driver=database`
and surfaces a configuration hint (rather than a misleadingly partial export)
under the cache driver. Successful exports dispatch a `MemoryDumped` event and
emit a `command.memory.dump` audit category recording what left the system —
including the requesting OS user (`requested_by`) and an optional `--reason` so
the egress record names who ran the export and why. See
the [compliance audit guide](compliance-audit.md#exporting-an-audit-packet) for
the end-to-end audit workflow.

### v0.9.0 scope coverage

The snapshot captures only `MemoryScope::Run` entries. Conversation, Agent, and Swarm scope entries are **not** snapshotted — reads against those scopes during replay fall through to the live store, and a `MemoryScopeOutOfSnapshot` event fires for each one. This is a pragmatic trade-off: most agents operate on Run-scoped data, and snapshotting the wider scope graph would require coordinating across runs and agent lifecycles that may be long-lived.

---

## Replay semantics

When a durable run crashes mid-step and is retried, the runner needs to decide what memory the agent sees on the second attempt. Two modes are available, controlled by `swarm.memory.replay_mode`.

### `frozen_view` (default)

The agent re-executes against the `MemoryScope::Run` entries frozen in the snapshot captured at the original invocation. Live writes to Run scope during the retry are buffered and never reach the backing store, preserving the canonical audit record. This is the recommended mode for reproducible, audit-friendly runs.

### `fresh_execution`

The agent re-executes against live memory with no snapshot guard. Use only when idempotency is guaranteed externally and you deliberately want the retry to see any writes that arrived between the original invocation and the retry.

### Configuration

```php
// config/swarm.php
'memory' => [
    'replay_mode' => env('SWARM_MEMORY_REPLAY_MODE', 'frozen_view'),
],
```

```ini
# .env
SWARM_MEMORY_REPLAY_MODE=frozen_view
```

### Per-swarm override with `#[MemoryReplay]`

Override the global setting for a single swarm class using the `#[MemoryReplay]` attribute:

```php
use BuiltByBerry\LaravelSwarm\Attributes\MemoryReplay;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;

#[MemoryReplay(mode: ReplayMode::FreshExecution)]
class MyIdempotentSwarm extends Swarm
{
    // …
}
```

`ReplayMode` cases:

| Case | Value | Behavior |
| --- | --- | --- |
| `ReplayMode::FrozenView` | `frozen_view` | Deterministic replay from frozen snapshot (default) |
| `ReplayMode::FreshExecution` | `fresh_execution` | Live memory, no snapshot guard |

When the attribute is absent the global `swarm.memory.replay_mode` config applies.

### Binding-restore constraint

`MemoryReplayCoordinator::during()` implements the frozen-view swap by resolving the original `SwarmMemory` binding via `app()->make(SwarmMemory::class)`, installing a `ReplaySwarmMemory` decorator via `app()->instance(SwarmMemory::class, $replay)` for the duration of the callback, then restoring the original binding via `app()->instance(SwarmMemory::class, $original)` in a `finally` block.

**Known constraint:** the restore step uses `instance()`, which always registers a singleton. If your application binds `SwarmMemory` as a factory (a non-singleton closure or a transient binding), the restore step silently converts it to singleton behavior for subsequent resolutions. The default binding is a singleton (`DefaultSwarmMemory`), so this does not affect standard setups. If you have customized the `SwarmMemory` binding to be non-singleton, document this trade-off in your service provider and verify the behavior under replay.

---

## RunContext bridge

`RunContext` — the envelope every swarm run carries — writes through to `SwarmMemory` automatically. You do not need to resolve `SwarmMemory` directly to record per-run state; anything written to `RunContext` via `mergeData()` is mirrored into `MemoryScope::Run` for the same run.

### ArrayAccess on RunContext

`RunContext` implements `ArrayAccess` as of v0.9.0. Use it as a direct memory accessor from any code that already holds a context handle:

```php
// Write — mirrors into SwarmMemory::Run scope
$context['approval_status'] = 'pending';

// Read — reads from SwarmMemory::Run scope (falls back to $data array if SwarmMemory is not bound)
$status = $context['approval_status']; // 'pending'

// Check
if (isset($context['draft_id'])) {
    // …
}

// Delete
unset($context['draft_id']);
```

Offset reads are backed by `SwarmMemory::get(MemoryScope::Run, $runId, $key)`. Offset writes call `SwarmMemory::put()` and also update the local `$data` array so prompt interpolation and inter-step relays remain array-fast (Eloquent attribute-caching pattern — the memory store is the write-through target, not the hot-path read source).

### `mergeData()` write-through

The existing `mergeData()` method also mirrors to memory:

```php
$context->mergeData(['summary' => $agentOutput, 'word_count' => $wordCount]);
// Both keys are also written to MemoryScope::Run via SwarmMemory::put()
```

Callers that pass a structured task array to `prompt()` — `BlogPostSwarm::make()->prompt(['topic' => 'AI', 'tone' => 'technical'])` — have always stored that array in `$data`. As of v0.9.0 those same keys are also persisted to memory without any code change.

### Null-bind tolerance

If no `SwarmMemory` is bound in the container (POPO test setups, lightweight unit tests), the write-through path is a no-op. The `$data` array is still updated locally, so all existing test code continues to work without wiring memory.

### How this relates to the memory subsystem

The write-through does not replace direct `SwarmMemory` reads and writes — it complements them. Use `$context['key']` when you already hold a `RunContext` and want to read or write a Run-scoped value as part of the run lifecycle. Use `app(SwarmMemory::class)->get(MemoryScope::Agent, AgentClass::class, 'key')` when you want to read from a different scope or when you are outside the run lifecycle (for example, in a listener or background task).

---

## Pulse observability

When Laravel Pulse is installed, the optional `<livewire:swarm.memory />` card surfaces four signals operators tune retention and capture policy against:

- **Entries written per scope** — count of `put()` calls grouped by `MemoryScope` (Run / Conversation / Agent / Swarm).
- **Average bytes per write** — approximate JSON byte size of the persisted `value` + `metadata`, averaged per scope.
- **Recall hit rate** — `MemoryRead` hits divided by total reads, per scope. Sustained low hit rates often indicate a propagation policy or scope mismatch.
- **Snapshot footprint** — total snapshot count, average bytes per persisted snapshot row, and average entries per snapshot. Ballooning snapshot sizes are an early warning that capture policy is letting too much payload through.

The card is registered automatically by `php artisan swarm:install:pulse` (re-run with `--force` after upgrading to pick up the new card and recorder), or you can wire it manually:

```php
// config/pulse.php
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmMemoryMetrics;

'recorders' => [
    SwarmMemoryMetrics::class => [
        'enabled' => env('PULSE_SWARM_MEMORY_METRICS_ENABLED', true),
    ],
],
```

```blade
{{-- resources/views/vendor/pulse/dashboard.blade.php --}}
<livewire:swarm.memory cols="6" />
```

### Tuning the sample rate

`swarm.pulse.memory.sample_rate` controls how often the recorder samples memory events. Default is `1.0` (record every event). High-volume apps should lower this:

```env
SWARM_PULSE_MEMORY_SAMPLE_RATE=0.1   # ~10% sampling
SWARM_PULSE_MEMORY_SAMPLE_RATE=0.0   # disable entirely
```

Sampling is uniform across writes, reads, and snapshots — so averages reported by the card remain statistically meaningful at any rate above zero. Counts (total entries, total snapshots) scale linearly with the sample rate, so multiply by `1 / sample_rate` if you need an absolute estimate.

### Tuning when the card raises an alarm

The card itself does not raise red-state alarms; it surfaces numbers an operator interprets. The common signals and the knobs that move them:

- **Entries-per-scope growing unbounded** — schedule `php artisan swarm:memory:purge` more aggressively, or tighten the retention window in `swarm.memory.retention.*` (v0.10.0).
- **Average bytes per write climbing** — review `swarm.capture.outputs` and `swarm.capture.artifacts`; tighten the metadata allowlist; reduce the size of values being written through `RunContext` write-through.
- **Snapshot bytes spike with no entries change** — a single agent is appending large tool-call payloads. Inspect the offending run with `php artisan swarm:inspect <run-id>` and consider redacting that tool's input/output via the capture policy.
- **Recall hit rate near 0%** — agents are reading keys that were never written. Confirm the propagation policy (`MemoryPropagationPolicy`, v0.10.0) carries the expected scopes into the worker context.

For aggregate Pulse internals — period selectors, recorder enable flag, troubleshooting — see [Pulse](pulse.md#swarm-memory-card).

---

## See Also

- [Memory Recipes](memory-recipes.md) — worked patterns for the Recall/Remember tools: per-user and tenant-scoped recall, policy-enforced custom tools, recall + redact, and sub-agent memory continuity
- [RunContext](run-context.md) — the envelope that carries input, identity, and carry-forward data through a run; includes ArrayAccess reference
- [Lifecycle Events](events.md) — every swarm lifecycle event, including memory events
- [Durable Execution](durable-execution.md) — checkpointing, crash-resume, and replay
- [Configuration](configuration.md) — `swarm.memory.replay_mode` and all memory config keys
- [Public Surface](public-surface.md) — `SwarmMemory`, `MemoryEntry`, `MemoryScope`, `MemorySnapshot`, `ReplayMode`, `#[MemoryReplay]` in the public surface matrix
- [laravel-swarm-memory-vector](https://github.com/builtbyberry/laravel-swarm-memory-vector) — vector-backed recall companion package (v0.1.0+, requires laravel-swarm v0.9.0+)
