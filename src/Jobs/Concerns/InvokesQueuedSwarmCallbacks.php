<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs\Concerns;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;

trait InvokesQueuedSwarmCallbacks
{
    /**
     * @var array<int, SerializableClosure>
     */
    protected array $thenCallbacks = [];

    /**
     * @var array<int, SerializableClosure>
     */
    protected array $catchCallbacks = [];

    /**
     * Invoke the given closure then invoke the "then" callbacks.
     *
     * @param  Closure(): mixed  $action
     */
    protected function withCallbacks(Closure $action): mixed
    {
        $response = $action();

        foreach ($this->thenCallbacks as $callback) {
            $callback($response);
        }

        return $response;
    }

    /**
     * Add a callback to be executed after the swarm is invoked.
     */
    public function then(Closure $callback): self
    {
        $this->thenCallbacks[] = new SerializableClosure($callback);

        return $this;
    }

    /**
     * Add a callback to be executed if the job fails.
     */
    public function catch(Closure $callback): self
    {
        $this->catchCallbacks[] = new SerializableClosure($callback);

        return $this;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $e): void
    {
        foreach ($this->catchCallbacks as $callback) {
            $callback($e);
        }
    }
}
