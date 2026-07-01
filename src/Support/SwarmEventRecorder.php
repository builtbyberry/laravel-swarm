<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Testing\InteractsWithSwarmEvents;

/**
 * @internal
 */
class SwarmEventRecorder
{
    /**
     * @var array<int, object>
     */
    protected array $events = [];

    protected bool $active = false;

    /**
     * The swarm lifecycle events the recorder captures for `assertEventFired()`.
     * Single source of truth: the {@see InteractsWithSwarmEvents}
     * trait registers a forwarding listener for each, so there is one list to
     * maintain rather than a copy per test suite.
     *
     * @return list<class-string>
     */
    public static function recordableEvents(): array
    {
        return [
            SwarmStarted::class,
            SwarmStepStarted::class,
            SwarmStepCompleted::class,
            SwarmCompleted::class,
            SwarmFailed::class,
            SwarmPaused::class,
            SwarmResumed::class,
            SwarmCancelled::class,
        ];
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function record(object $event): void
    {
        if (! $this->active) {
            return;
        }

        $this->events[] = $event;
    }

    public function resetRecorder(): void
    {
        $this->events = [];
    }

    /**
     * @return array<int, object>
     */
    public function eventsFor(string $swarmClass, string $eventClass): array
    {
        return array_values(array_filter($this->events, function (object $event) use ($swarmClass, $eventClass): bool {
            if (! $event instanceof $eventClass) {
                return false;
            }

            return property_exists($event, 'swarmClass') && $event->swarmClass === $swarmClass;
        }));
    }
}
