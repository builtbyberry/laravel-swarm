<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\Concerns\InvokesQueuedSwarmCallbacks;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class InvokeSwarm implements ShouldQueue
{
    use InvokesQueuedSwarmCallbacks;
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $swarmClass,
        public array $task,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SwarmRunner $runner): void
    {
        $swarm = app()->make($this->swarmClass);
        $context = RunContext::fromPayload($this->task);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve queued swarm [{$this->swarmClass}] from the container.");
        }

        $this->withCallbacks(fn () => $runner->runQueued($swarm, $context));
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->swarmClass;
    }
}
