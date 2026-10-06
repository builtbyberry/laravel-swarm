<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Enums\CallbackSlot;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use Illuminate\Contracts\Support\Arrayable;

/**
 * The settled-terminal summary handed to a queue/durable `then()` / `catch()`
 * callback when it is delivered.
 *
 * Terminal callbacks are delivered asynchronously by `swarm:relay`, in a
 * different process (and possibly a different host) from the one that ran the
 * workflow. The full {@see SwarmResponse} — with captured output, steps, and
 * artifacts — is not reconstructed at delivery time (capture is off by default
 * and payloads are sensitive). Instead the callback receives this lightweight,
 * always-available summary built from the terminal lifecycle record: the same
 * identity fields carried by {@see SwarmCompleted}
 * and {@see SwarmFailed}. A callback that needs
 * the full response should read it from `SwarmHistory` by run ID, or listen to the
 * lifecycle events directly.
 *
 * This is the deliberate line the component's Goal draws: convenience callbacks
 * signal that the WHOLE WORKFLOW settled, without pretending an agent's in-memory
 * completion value survived the crossing to another process.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class SwarmTerminalContext implements Arrayable
{
    public function __construct(
        public string $runId,
        public CallbackSlot $slot,
        public string $swarmClass,
        public ?string $topology = null,
        public ?string $exceptionClass = null,
        public ?string $exceptionMessage = null,
    ) {}

    /**
     * True when the workflow settled as completed (a `then` outcome).
     */
    public function succeeded(): bool
    {
        return $this->slot === CallbackSlot::Then;
    }

    /**
     * True when the workflow settled as a failure (a `catch` outcome).
     */
    public function failed(): bool
    {
        return $this->slot === CallbackSlot::Catch;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'slot' => $this->slot->value,
            'swarm_class' => $this->swarmClass,
            'topology' => $this->topology,
            'exception_class' => $this->exceptionClass,
            'exception_message' => $this->exceptionMessage,
        ];
    }
}
