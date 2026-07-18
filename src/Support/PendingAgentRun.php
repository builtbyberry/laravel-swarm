<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use Illuminate\Broadcasting\Channel;

/**
 * Fluent entry point for running a single agent through the full governed
 * Swarm pipeline without authoring a {@see \BuiltByBerry\LaravelSwarm\Contracts\Swarm}
 * class.
 *
 * Returned by {@see \BuiltByBerry\LaravelSwarm\Runners\SwarmRunner::agent()}
 * (reachable as `Swarm::agent($agent)`). Each terminal call materializes a
 * fresh {@see AdHocSwarm} and dispatches it through the same
 * {@see \BuiltByBerry\LaravelSwarm\Runners\SwarmRunner} every multi-agent run
 * uses, so the lone agent inherits audit, guardrails, capture, telemetry and
 * encrypt-at-rest identically — and every execution mode.
 *
 *   Swarm::agent($agent)->prompt($task);
 *   Swarm::agent($agent)->guardrails([BudgetGuardrail::class])->stream($task);
 *
 * Guardrails passed here are additive: they merge with the app's globally
 * configured guardrails, they do not replace them.
 *
 * @phpstan-import-type SwarmTaskInput from PhpStanTypeAliases
 * @phpstan-import-type SwarmBroadcastChannels from PhpStanTypeAliases
 */
class PendingAgentRun
{
    /**
     * Per-call guardrail refs (class names or instances) layered on top of the
     * globally configured guardrails.
     *
     * @var array<int, object|class-string>
     */
    protected array $guardrails = [];

    public function __construct(protected Agent $agent) {}

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
     * Run the agent synchronously and return the terminal response.
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
     * Stream the run, yielding typed stream events for SSE.
     *
     * @param  SwarmTaskInput  $task
     */
    public function stream(string|array|RunContext $task): StreamableSwarmResponse
    {
        return $this->toSwarm()->stream($task);
    }

    /**
     * Queue the run to execute in the background.
     *
     * @param  SwarmTaskInput  $task
     */
    public function queue(string|array|RunContext $task): QueuedSwarmResponse
    {
        return $this->toSwarm()->queue($task);
    }

    /**
     * Broadcast typed stream events for the run.
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
     * Queue the run's stream and broadcast each event from the worker.
     *
     * @param  SwarmTaskInput  $task
     * @param  SwarmBroadcastChannels  $channels
     */
    public function broadcastOnQueue(string|array|RunContext $task, Channel|array $channels): QueuedSwarmResponse
    {
        return $this->toSwarm()->broadcastOnQueue($task, $channels);
    }

    /**
     * Dispatch the run on the durable runtime.
     *
     * @param  SwarmTaskInput  $task
     */
    public function dispatchDurable(string|array|RunContext $task): DurableSwarmResponse
    {
        return $this->toSwarm()->dispatchDurable($task);
    }

    /**
     * Materialize the one-element swarm carrying the collected guardrails.
     */
    protected function toSwarm(): AdHocSwarm
    {
        return new AdHocSwarm([$this->agent], $this->guardrails);
    }
}
