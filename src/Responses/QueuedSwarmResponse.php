<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\Concerns\RegistersTerminalCallbacks;
use Illuminate\Foundation\Bus\PendingDispatch;

class QueuedSwarmResponse
{
    use RegistersTerminalCallbacks;

    public function __construct(
        protected PendingDispatch $dispatchable,
        public readonly ?string $runId = null,
    ) {}

    protected function terminalCallbackRunId(): string
    {
        if ($this->runId === null) {
            throw new SwarmException('Cannot register a terminal callback: this queued swarm response has no run id.');
        }

        return $this->runId;
    }

    protected function terminalCallbackResponseNoun(): string
    {
        return 'queued';
    }

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
