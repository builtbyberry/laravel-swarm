<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

class SwarmEventRecorder
{
    /**
     * @var array<int, object>
     */
    protected array $events = [];

    protected bool $active = false;

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
