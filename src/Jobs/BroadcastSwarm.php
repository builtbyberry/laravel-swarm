<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\Concerns\InvokesQueuedSwarmCallbacks;
use BuiltByBerry\LaravelSwarm\Responses\StreamedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BroadcastSwarm implements ShouldQueue
{
    use InteractsWithQueue;
    use InvokesQueuedSwarmCallbacks;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $swarmClass,
        public array $task,
        public Channel|array $channels,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SwarmRunner $runner): void
    {
        $swarm = Container::getInstance()->make($this->swarmClass);
        $context = RunContext::fromPayload($this->task);

        if (! $swarm instanceof Swarm) {
            throw new SwarmException("Unable to resolve broadcast swarm [{$this->swarmClass}] from the container.");
        }

        $streamedResponse = null;

        $runner->stream($swarm, $context)
            ->each(function (SwarmStreamEvent $event): void {
                $event->broadcastNow($this->channels);
            })
            ->then(function (StreamedSwarmResponse $response) use (&$streamedResponse): void {
                $streamedResponse = $response;
            });

        if (! $streamedResponse instanceof StreamedSwarmResponse) {
            throw new SwarmException("Broadcast swarm [{$this->swarmClass}] finished without a streamed response.");
        }

        $this->withCallbacks(fn () => $streamedResponse);
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->swarmClass;
    }
}
