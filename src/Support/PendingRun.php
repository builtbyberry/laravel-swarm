<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use Illuminate\Broadcasting\Channel;

/**
 * Shared fluent surface for the class-free swarm entry points
 * ({@see SwarmRunner::agent()},
 * `sequential()`, `parallel()`, `hierarchical()`).
 *
 * Each terminal call materializes a fresh {@see AdHocSwarm} via {@see toSwarm()}
 * and dispatches it through the same {@see SwarmRunner}
 * a hand-authored swarm uses, so an inline swarm inherits audit, guardrails,
 * capture, telemetry and encrypt-at-rest identically.
 *
 * The class-free builders expose the **in-process** execution modes only —
 * `prompt()`/`run()`, `stream()`, and `broadcast()`/`broadcastNow()`. The
 * background modes (`queue()`, `broadcastOnQueue()`, `dispatchDurable()`) are
 * intentionally NOT offered here: a queued/durable run is dispatched as a job
 * and re-resolved from the container by class on a (possibly much later, post-
 * deploy) worker, which requires a stable, container-bound identity — exactly
 * what an ad-hoc swarm built from runtime agent instances cannot provide. For
 * background or recoverable execution, author a one-agent {@see Swarm}
 * class (`php artisan make:swarm:swarm YourSwarm`) and call `queue()` /
 * `dispatchDurable()` on it.
 *
 * (Streamability is still a topology property: `parallel` swarms cannot
 * `stream()`/`broadcast()` — that constraint is enforced by the runner and
 * applies to class-based parallel swarms too.)
 *
 * Guardrails passed via {@see guardrails()} are additive: they merge with the
 * app's globally configured guardrails, they do not replace them.
 *
 * @phpstan-import-type SwarmTaskInput from PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from PhpStanTypeAliases
 */
abstract class PendingRun
{
    /**
     * Per-call guardrail refs (class names or instances) layered on top of the
     * globally configured guardrails.
     *
     * @var array<int, object|class-string>
     */
    protected array $guardrails = [];

    /**
     * Attach per-call guardrails for this run. Additive to the globally
     * configured guardrails, not a replacement.
     *
     * @param  array<int, object|class-string>  $guardrails
     */
    public function guardrails(array $guardrails): static
    {
        $this->guardrails = array_values($guardrails);

        return $this;
    }

    /**
     * Run synchronously and return the terminal response.
     *
     * @param  SwarmTaskInput  $task
     */
    public function prompt(string|array|RunContext $task): SwarmResponse
    {
        return $this->toSwarm()->prompt($task);
    }

    /**
     * Compatibility alias for {@see prompt()}.
     *
     * @param  SwarmTaskInput  $task
     */
    public function run(string|array|RunContext $task): SwarmResponse
    {
        return $this->prompt($task);
    }

    /**
     * Stream the run, yielding typed stream events for SSE. Sequential,
     * hierarchical, and static-hierarchical topologies stream; parallel does not.
     *
     * @param  SwarmTaskInput  $task
     */
    public function stream(string|array|RunContext $task): StreamableSwarmResponse
    {
        return $this->toSwarm()->stream($task);
    }

    /**
     * Broadcast typed stream events for the run (in-process, like {@see stream()}).
     *
     * @param  SwarmTaskInput  $task
     * @param  SwarmBroadcastChannels  $channels
     */
    public function broadcast(string|array|RunContext $task, Channel|array $channels, bool $now = false): StreamableSwarmResponse
    {
        return $this->toSwarm()->broadcast($task, $channels, $now);
    }

    /**
     * Broadcast typed stream events immediately.
     *
     * @param  SwarmTaskInput  $task
     * @param  SwarmBroadcastChannels  $channels
     */
    public function broadcastNow(string|array|RunContext $task, Channel|array $channels): StreamableSwarmResponse
    {
        return $this->broadcast($task, $channels, now: true);
    }

    /**
     * Materialize the ad-hoc swarm this pending run dispatches.
     */
    abstract protected function toSwarm(): AdHocSwarm;
}
