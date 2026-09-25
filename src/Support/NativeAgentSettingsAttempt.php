<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

/** @internal Attempt-local one-shot consumption staged until its owning checkpoint commits. */
final class NativeAgentSettingsAttempt
{
    /** @var array<string, true> */
    protected array $consumed = [];

    /** @param list<string> $ids */
    public function __construct(array $ids = [])
    {
        $this->merge($ids);
    }

    public function consumed(string $id): bool
    {
        return isset($this->consumed[$id]);
    }

    public function stage(string $id): void
    {
        $this->consumed[$id] = true;
    }

    /** @param list<string> $ids */
    public function merge(array $ids): void
    {
        foreach ($ids as $id) {
            if ($id !== '') {
                $this->consumed[$id] = true;
            }
        }
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->consumed);
    }
}
