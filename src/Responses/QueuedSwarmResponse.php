<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use Closure;
use Laravel\Ai\FakePendingDispatch;

class QueuedSwarmResponse
{
    public function __construct(
        protected mixed $dispatchable,
        public readonly ?string $runId = null,
    ) {}

    /**
     * Register a callback to invoke when the swarm completes.
     */
    public function then(Closure $callback): self
    {
        if ($this->dispatchable instanceof FakePendingDispatch) {
            return $this;
        }

        if (method_exists($this->dispatchable, 'getJob')) {
            $this->dispatchable->getJob()->then($callback);

            return $this;
        }

        if (method_exists($this->dispatchable, 'then')) {
            $this->dispatchable->then($callback);
        }

        return $this;
    }

    /**
     * Register a callback to invoke if the swarm fails.
     */
    public function catch(Closure $callback): self
    {
        if ($this->dispatchable instanceof FakePendingDispatch) {
            return $this;
        }

        if (method_exists($this->dispatchable, 'getJob')) {
            $this->dispatchable->getJob()->catch($callback);

            return $this;
        }

        if (method_exists($this->dispatchable, 'catch')) {
            $this->dispatchable->catch($callback);
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
        if (! is_object($this->dispatchable) || ! method_exists($this->dispatchable, $method)) {
            throw new \BadMethodCallException("Method [{$method}] does not exist on the queued swarm response.");
        }

        return $this->dispatchable->{$method}(...$arguments);
    }
}
