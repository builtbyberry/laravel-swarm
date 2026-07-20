# Memory Recipes

This is the pattern companion to [Recall and Remember tools](memory.md#recall-and-remember-tools).
Where [Swarm Memory](memory.md) documents the contracts — scopes, the propagation
and capture policies, snapshots — this page shows how those pieces compose into
the patterns teams actually ship: scoping a recall to the current user, isolating
tenants, baking a read policy into the tool itself, redacting PII at the write
boundary, and giving a reusable sub-agent memory that survives across runs.

Every recipe here is built on the real extension hooks the shipped `Recall` and
`Remember` tools expose, so each one is a subclass you can scaffold with
[`make:memory-tool`](generators.md#make-memory-tool) and drop into any
`laravel/ai` agent's `tools()` array. Two facts hold across all of them, and the
recipes lean on both:

- **The scope id is framework-owned.** A tool takes a scope *name* from the model
  (`run`, `swarm`, `agent`, `conversation`) but never a scope *id* — the id is
  resolved from the active run, so an agent can never address another run's,
  swarm's, or agent's memory by guessing.
- **Reads honour the propagation policy; writes honour the capture policy.** A
  custom tool narrows what it surfaces; it can never widen past what the active
  swarm's policies already permit.

> **Prerequisite.** The memory tools are off by default. Set
> `swarm.memory.tools.enabled` (env `SWARM_MEMORY_TOOLS_ENABLED`) to `true` before
> any of these recipes take effect — granting an LLM read/write access to shared
> memory is an explicit decision. Review your propagation and capture policies
> first. See [Optional default-on registration](memory.md#optional-default-on-registration).

---

## Per-user scoped recall

**Problem.** An agent runs inside a multi-user application and writes per-user
notes to a shared scope. You need it to recall *only the authenticated user's*
entries — never another user's — even if the model asks for a key it shouldn't
see.

**Solution.** Subclass `Recall` and override `visibleEntries()`. The base method
already gathers entries through the active swarm's propagation policy; your
override narrows that set to keys carrying the current user's prefix. Because the
model never supplies the user id — your tool injects it from `auth()` — there is
no key it can pass to read across the boundary.

```php
namespace App\Ai\Tools;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Tools\Recall;

class UserRecall extends Recall
{
    /**
     * Narrow the policy-permitted entries to the authenticated user's keys.
     *
     * @return array<int, MemoryEntry>|null
     */
    protected function visibleEntries(MemoryScope $scope): ?array
    {
        $entries = parent::visibleEntries($scope);

        if ($entries === null) {
            return null; // No active run — let the base tool report unavailability.
        }

        $prefix = 'user:'.auth()->id().':';

        return array_values(array_filter(
            $entries,
            static fn (MemoryEntry $entry): bool => str_starts_with($entry->key, $prefix),
        ));
    }
}
```

Write the matching entries under the same convention (for example a `Remember`
subclass, or application code, storing `user:42:preferences`), and the agent can
only ever recall the row that belongs to the user it is acting for.

> **Prerequisite: a request-bound auth context.** The boundary here is
> `auth()->id()`, so it only holds where the guard is populated — an HTTP request.
> In a **queued, durable, or console** run (exactly the runs Swarm memory is built
> to survive) `auth()` is typically empty, the prefix collapses to `user::`, and
> the recall returns nothing. That fails *closed* — no other user's data leaks —
> but the tool is silently inert. For agents that run off the web guard, either
> rehydrate the user on the worker, or scope by identity in a **propagation
> policy** (the shape in [Tenant-isolated memory](#tenant-isolated-memory)), which
> receives the run's `RunContext` and so can read an id you carried on it.

> **Prerequisite: a policy that gathers the scope.** `visibleEntries()` narrows
> what `parent::visibleEntries()` already presented through the active propagation
> policy — and the shipped `DefaultPropagationPolicy` gathers **Run scope only**.
> So `UserRecall` over the default policy only ever sees Run-scoped keys. To recall
> per-user entries that live in **Agent or Swarm scope**, pair it with a propagation
> policy whose `scopes()` includes that scope (see
> [Propagation policy](memory.md#propagation-policy)); otherwise keep the per-user
> keys in Run scope.

**When to use.** Per-request agents in a multi-user app where several users'
memory lives in one scope and the separation is by key, not by run. If each
user's request is already its own isolated run, Run scope gives you this for
free — reach for `UserRecall` when entries outlive a single run (Agent or Swarm
scope), you have a propagation policy gathering that scope, and they must stay
partitioned by user.

---

## Tenant-isolated memory

**Problem.** A single swarm class serves many tenants, and one tenant must never
recall another tenant's state.

**Solution.** Start from the boundary the tool already gives you, then close the
gap deliberately. `run` scope is isolated for free — every run has its own id, so
a tenant's run-scoped memory is invisible to every other run. The trap is
`swarm` scope:

> **`swarm` scope is shared across every tenant of that swarm class.** A
> `swarm`-scope write is addressed by the swarm *class*, not the tenant, so it is
> readable by every run — and every tenant — of that class. See the
> [multi-tenant note](memory.md#scopes-and-addressing) on the tools reference.

The same trap applies to `conversation` scope: it is addressed by the
app-supplied conversation id, so a reused id (per-tenant sequential numbers, raw
thread ids) leaks one tenant's conversation memory into another's. Namespace
conversation ids per tenant or bound them in the policy below — see the
[conversation-scope multi-tenant note](memory.md#conversation-scoped-memory).

So for cross-run state that must stay tenant-private, pick one of two honest
approaches:

1. **Partition by swarm class.** Give each tenant its own swarm class (or a
   thin per-tenant subclass). Swarm scope is then naturally tenant-scoped because
   the class *is* the tenant boundary.
2. **Enforce in a propagation policy.** Keep one swarm class but filter what any
   agent can see to the current tenant. Because `Recall` reads through the
   propagation policy, a tenant-aware policy bounds the tool automatically — no
   tool subclass required.

```php
namespace App\Memory;

use Laravel\Ai\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

final class TenantPropagationPolicy implements MemoryPropagationPolicy
{
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
        $prefix = 'tenant:'.($context->data['tenant_id'] ?? '').':';

        return array_values(array_filter(
            $candidateEntries,
            static fn (MemoryEntry $entry): bool =>
                $entry->scope === MemoryScope::Run
                || str_starts_with($entry->key, $prefix),
        ));
    }
}
```

Bind it globally via `swarm.memory.propagation_policy`, or per swarm with the
`#[PropagationPolicy(TenantPropagationPolicy::class)]` attribute. Either way,
`Recall`, `RemembersRunContext`, and the frozen snapshot all inherit the same
tenant boundary — there is no second place to enforce it.

**When to use.** Multi-tenant deployments sharing a swarm class. Prefer
class-partitioning when tenants are few and long-lived; prefer the policy when
tenancy is dynamic and you carry the tenant id on the `RunContext`. Do not rely
on `swarm` scope alone for tenant isolation.

---

## Policy-enforced custom Recall

**Problem.** You want a recall tool an agent *cannot* use to read outside an
allowed boundary — regardless of what the model passes — so the read policy lives
in the tool, not in a prompt instruction the model can ignore.

**Solution.** Make the tool itself the chokepoint. Two overrides cover most
needs, and they compose:

- Override `resolveScope()` to refuse any scope outside an allow-list, so the tool
  only ever reads from scopes you sanctioned (here, `run` only — `swarm` and
  `agent` reads are rejected before any lookup).
- Override `visibleEntries()` to apply a key allow-list, so even within the
  permitted scope the tool surfaces only the keys you named.

```php
namespace App\Ai\Tools;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Tools\Recall;

class RunOnlyRecall extends Recall
{
    /** Keys this tool is ever allowed to surface. */
    private const ALLOWED_KEYS = ['summary', 'findings', 'next_steps'];

    /**
     * Refuse any scope but Run. Returning null makes the base tool report an
     * unknown scope to the model, exactly as a genuinely unknown name would.
     */
    protected function resolveScope(string $scope): ?MemoryScope
    {
        $resolved = parent::resolveScope($scope);

        return $resolved === MemoryScope::Run ? $resolved : null;
    }

    /**
     * @return array<int, MemoryEntry>|null
     */
    protected function visibleEntries(MemoryScope $scope): ?array
    {
        $entries = parent::visibleEntries($scope);

        if ($entries === null) {
            return null;
        }

        return array_values(array_filter(
            $entries,
            static fn (MemoryEntry $entry): bool => in_array($entry->key, self::ALLOWED_KEYS, true),
        ));
    }
}
```

Scaffold the skeleton with `php artisan make:memory-tool RunOnlyRecall` and fill
in the overrides — the generated stub already exposes `resolveScope()` and a
`$defaultScope` property as the hooks to specialise. See
[`make:memory-tool`](generators.md#make-memory-tool).

**When to use.** Whenever the read boundary is a security or compliance
requirement rather than a hint. A tool-level allow-list survives prompt injection
and model error in a way an instruction in `instructions()` does not. Pair it
with a propagation policy for defence in depth: the policy bounds what any agent
can see; the tool bounds what *this* tool will surface within that.

---

## Recall + redact for compliance

**Problem.** An agent decides, mid-prompt, to `Remember` something that turns out
to contain PII. It must never land in memory unredacted, and a later agent that
`Recall`s the same key must see the redacted form — not the original.

**Solution.** Redaction is a write-boundary concern, so you don't touch the
tools at all — you bind a `MemoryCapturePolicy`. Every `Remember` write flows
through `SwarmMemory::put()`, which the `RedactingMemoryStore` decorator wraps, so
a `CaptureDecision::Redact` for a sensitive key replaces scalar values with the
`[redacted]` sentinel before the row is written. A subsequent `Recall` reads the
already-redacted value back.

```php
namespace App\Memory;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

final class RedactPiiPolicy implements MemoryCapturePolicy
{
    public function memory(MemoryScope $scope, string $key, ?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return in_array($key, ['ssn', 'card_number', 'email'], true)
            ? CaptureDecision::Redact
            : CaptureDecision::Full;
    }
}
```

The key list above is illustrative. The policy is handed only the **key**, never
the value, so consistent key naming — or a deliberate prefix convention like
`pii:*` — is the actual control: a near-miss key (`social_security`, a nested
`applicant.ssn`) that isn't in the list falls through as `Full` and is stored
verbatim. Treat the allow-list as a contract your write paths must honour.

Bind the policy globally — via config or the matching env override:

```php
// config/swarm.php
'memory' => [
    'capture_policy' => \App\Memory\RedactPiiPolicy::class,
],
```

```bash
# or via env (the shipped default reads this key)
SWARM_MEMORY_CAPTURE_POLICY="App\\Memory\\RedactPiiPolicy"
```

Now the flow is automatic:

```text
Agent A: remember(key: "ssn", value: "123-45-6789")
  → RedactingMemoryStore writes { ssn: "[redacted]" }, dispatches MemoryRedacted
Agent B: recall(key: "ssn")
  → "ssn: [redacted]"
```

The policy **never receives the value** — only the scope and key — so a decision
cannot couple to payload shape or leak the unredacted data. Redaction covers the
entry **value** only: keys stay intact (they are addressing) and so does
`metadata`, so don't put PII in either. Because the snapshot freezes the
already-redacted view, the PII never reaches a frozen `MemorySnapshot` or a
`swarm:memory:dump` export.

**When to use.** Any regulated workload where agents may write free-form values
you can't fully trust to be PII-free. See
[Capture policy (write-time redaction)](memory.md#capture-policy-write-time-redaction)
for the full decision model and
[Compliance & Audit → Memory capture policy](compliance-audit.md#memory-capture-policy)
for worked HIPAA-/SOX-aware configurations.

---

## Sub-agent with memory continuity

**Problem.** You have a reusable sub-agent — a classifier, a researcher, a
profile-builder — that should accumulate state *across* invocations and runs, not
start cold every time. Run scope is wrong (it's cleared with the run); you want
memory keyed to the agent itself.

**Solution.** That is exactly the `agent` scope — memory addressed by the agent
*class*, so it persists for that agent across every run. But `agent` scope is only
addressable when the tool knows which agent it acts as: the shipped `Recall` and
`Remember` are scope-driven and return `null` from `agent()` by default, so they
can't resolve it. Bind the tool to a concrete agent by overriding `agent()`.

Scaffold both halves with the generator:

```bash
php artisan make:memory-tool ProfileRecall --scope=agent
php artisan make:memory-tool ProfileRemember --base=remember --scope=agent
```

Then fill in the `agent()` hook the stub leaves as a `TODO`:

```php
namespace App\Ai\Tools;

use App\Ai\Agents\ProfileBuilder;
use Laravel\Ai\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Tools\Remember;

class ProfileRemember extends Remember
{
    protected MemoryScope $defaultScope = MemoryScope::Agent;

    public function name(): string
    {
        return 'profile_remember';
    }

    /**
     * Bind the tool to ProfileBuilder, so the `agent` scope resolves to that
     * agent's class — its memory persists across every run the agent runs in.
     */
    protected function agent(): ?Agent
    {
        return new ProfileBuilder;
    }
}
```

Now when `ProfileBuilder` calls `profile_remember`, the write is addressed to
`ProfileBuilder::class`; the next time the agent runs — in this swarm or any
other — its `ProfileRecall` reads the same entries back. Writes are tagged with
the agent class in their metadata, so `MemoryWritten` audit listeners can
attribute them.

**When to use.** Long-lived, reusable agents that should remember what they
learned: a support triager that recalls a customer's history, a researcher that
builds on prior findings, an onboarding agent that resumes where it left off.
Keep agent-scoped memory under retention control (`swarm.memory.retention.days.agent`)
since, unlike Run scope, it is not cleared automatically.

---

## See also

- [Swarm Memory](memory.md) — the memory subsystem reference: scopes, policies,
  snapshots, replay.
- [Recall and Remember tools](memory.md#recall-and-remember-tools) — the tools'
  contract surface these recipes build on.
- [`make:memory-tool`](generators.md#make-memory-tool) — scaffold any of the
  custom tools above.
- [Compliance & Audit](compliance-audit.md) — capture policy, retention, and
  audit-packet export for regulated workloads.
