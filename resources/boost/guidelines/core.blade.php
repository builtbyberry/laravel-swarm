## Laravel Swarm

`builtbyberry/laravel-swarm` adds reusable **multi-agent orchestration** on top of the official `laravel/ai` package. It runs one or more `laravel/ai` agents through a governed pipeline — audit trail, guardrails, capture, telemetry, and encrypt-at-rest — across sequential, parallel, and hierarchical topologies, with synchronous, queued, streamed, and durable execution modes.

Everything a swarm run does is governed the same way whether it uses one agent or many. Prefer the paved path below over calling a bare `laravel/ai` agent directly, which bypasses all of this.

### The paved path — pick the smallest that fits

**One agent, no class.** Run a single agent through the full governed pipeline with one line:

@verbatim
<code-snippet name="Single agent, fully governed" lang="php">
use BuiltByBerry\LaravelSwarm\Facades\Swarm;

$response = Swarm::agent($agent)->prompt($task);
// also ->run($task), ->stream($task), ->broadcast($task, $channel)  (in-process modes)
$response->output;
</code-snippet>
@endverbatim

**Several agents, no class.** Compose a multi-agent swarm inline; each builder pins its topology:

@verbatim
<code-snippet name="Inline multi-agent swarms" lang="php">
Swarm::sequential([$researcher, $writer, $editor])->prompt($task);   // each output feeds the next
Swarm::parallel([$a, $b, $c])->prompt($task);                        // every agent sees the same task (parallel can't stream)
Swarm::hierarchical($coordinator, [$writer, $editor])->prompt($task); // coordinator routes over workers
</code-snippet>
@endverbatim

**A named, reusable swarm.** Author a class when the same topology is reused, carries class-level attributes (`#[Topology]`, `#[Timeout]`, `#[MaxAgentSteps]`, `#[DurableRetry]`, …), or declares guardrails via `DefinesGuardrails`. Generate one with `php artisan make:swarm:swarm`:

@verbatim
<code-snippet name="Class-based swarm" lang="php">
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Sequential)]
class ContentPipeline implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new Researcher, new Writer, new Editor];
    }
}

ContentPipeline::make()->prompt($task);
</code-snippet>
@endverbatim

### Conventions

- **In-process vs. background.** The class-free builders (`Swarm::agent()`/`sequential()`/`parallel()`/`hierarchical()`) run **in-process** — `prompt()`/`run()`/`stream()`/`broadcast()`. For queued or durable execution, author a `Swarm` class (`make:swarm:swarm --single` for one agent); background runs need a container-bound class to survive job re-resolution. `stream()`/`broadcast()` need a streamable topology (sequential/hierarchical/static-hierarchical, not parallel).
- **Governed by default.** The app's globally configured guardrails (`config('swarm.guardrails.*')`) always apply; per-call `->guardrails([...])` on the inline builders is additive, not a replacement.
- **Agents are `laravel/ai` agents.** Any class implementing `Laravel\Ai\Contracts\Agent` (or the swarm marker `BuiltByBerry\LaravelSwarm\Contracts\Agent`) works. Use `php artisan make:swarm:agent`.
- **Persistence & capture are opt-in.** Prompt/agent payloads are not persisted unless enabled in `config/swarm.php` (`swarm.capture.*`); when database persistence is on, sensitive columns are encrypted at rest.
- **Operate with the `swarm:*` Artisan commands** (`swarm:status`, `swarm:health`, `swarm:trace`, `swarm:recover`, …). Queued and durable runs need the queue worker running; durable runs also need `swarm:relay` scheduled every minute.

Use Boost's `search-docs` tool for depth on topologies, durable execution, memory, guardrails, and testing — and the `swarm-development` agent skill for end-to-end authoring patterns.
