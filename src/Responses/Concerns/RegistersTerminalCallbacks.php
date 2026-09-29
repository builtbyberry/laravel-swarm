<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses\Concerns;

use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Enums\CallbackSlot;
use BuiltByBerry\LaravelSwarm\Responses\SwarmTerminalContext;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Adds terminal-workflow `then()` / `catch()` to a dispatch-backed response
 * (queued or durable).
 *
 * A `then` callback fires once when the whole workflow settles as completed; a
 * `catch` callback fires once when it settles as a failure. The callback must be a
 * serializable, idempotent closure and receives a {@see SwarmTerminalContext} summary.
 * The delivery semantics (at-least-once, the relay lane, the events-vs-callbacks
 * contract, the terminal outcomes that do and do not fire) live in
 * docs/error-handling.md § Terminal Workflow Callbacks — this trait only registers
 * the callback.
 *
 * Feature-gated by `swarm.callbacks.enabled` (default off). With the flag off, both
 * methods throw the exact BadMethodCallException they threw before the feature
 * existed, when the call fell through to the PendingDispatch proxy.
 *
 * @property-read ?string $runId
 */
trait RegistersTerminalCallbacks
{
    /**
     * Register a callback invoked when the workflow settles as completed.
     */
    public function then(callable $callback): static
    {
        $this->registerTerminalCallback(CallbackSlot::Then, $callback);

        return $this;
    }

    /**
     * Register a callback invoked when the workflow settles as a failure.
     */
    public function catch(callable $callback): static
    {
        $this->registerTerminalCallback(CallbackSlot::Catch, $callback);

        return $this;
    }

    protected function registerTerminalCallback(CallbackSlot $slot, callable $callback): void
    {
        $container = Container::getInstance();

        $enabled = (bool) $container->make(ConfigRepository::class)->get('swarm.callbacks.enabled', false);

        if (! $enabled) {
            // Preserve the exact pre-feature behavior: before then()/catch() existed
            // on these responses, the call fell through __call to the PendingDispatch
            // proxy, which has no such method and threw this message.
            throw new \BadMethodCallException(
                "Method [{$slot->value}] does not exist on the {$this->terminalCallbackResponseNoun()} swarm response."
            );
        }

        $container->make(CallbackDeliveryOutbox::class)->register(
            $this->terminalCallbackRunId(),
            $slot,
            Closure::fromCallable($callback),
        );
    }

    /**
     * The run id the callback is keyed to.
     */
    abstract protected function terminalCallbackRunId(): string;

    /**
     * The response noun used in the flag-off BadMethodCallException ("queued" / "durable").
     */
    abstract protected function terminalCallbackResponseNoun(): string;
}
