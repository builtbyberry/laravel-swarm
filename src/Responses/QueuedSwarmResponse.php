<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use Illuminate\Foundation\Bus\PendingDispatch;

class QueuedSwarmResponse
{
    public function __construct(
        protected PendingDispatch $dispatchable,
        public readonly ?string $runId = null,
    ) {}

    /**
     * Proxy missing method calls to the pending dispatch instance.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (! method_exists($this->dispatchable, $method)) {
            throw new \BadMethodCallException("Method [{$method}] does not exist on the queued swarm response.");
        }

        $result = $this->dispatchable->{$method}(...$arguments);

        if ($result instanceof PendingDispatch) {
            $this->dispatchable = $result;

            return $this;
        }

        return $result;
    }
}
