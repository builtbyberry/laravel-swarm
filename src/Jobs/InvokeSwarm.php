<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
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
        public Swarm $swarm,
        public string|RunContext $task,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SwarmRunner $runner): void
    {
        $this->withCallbacks(fn () => $runner->run($this->swarm, $this->task));
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->swarm::class;
    }
}
