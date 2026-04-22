<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use Closure;
use Illuminate\Foundation\Bus\PendingDispatch;
use Laravel\Ai\FakePendingDispatch;

/**
 * @mixin PendingDispatch
 */
class QueuedSwarmResponse
{
    public function __construct(protected PendingDispatch $dispatchable) {}

    /**
     * Register a callback to invoke when the swarm completes.
     */
    public function then(Closure $callback): self
    {
        if (! $this->dispatchable instanceof FakePendingDispatch) {
            $this->dispatchable->getJob()->then($callback);
        }

        return $this;
    }

    /**
     * Register a callback to invoke if the swarm fails.
     */
    public function catch(Closure $callback): self
    {
        if (! $this->dispatchable instanceof FakePendingDispatch) {
            $this->dispatchable->getJob()->catch($callback);
        }

        return $this;
    }

    /**
     * Proxy missing method calls to the pending dispatch instance.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->dispatchable->{$method}(...$arguments);
    }
}
