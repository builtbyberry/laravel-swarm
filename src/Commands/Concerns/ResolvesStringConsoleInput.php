<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Concerns;

trait ResolvesStringConsoleInput
{
    protected function argumentString(string $name): string
    {
        $value = $this->argument($name);

        if (! is_string($value)) {
            throw new \InvalidArgumentException("Console argument [{$name}] must be a string.");
        }

        return $value;
    }

    protected function optionString(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            throw new \InvalidArgumentException("Console option [{$name}] must be a string.");
        }

        return $value;
    }

    protected function optionalOptionString(string $name): ?string
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new \InvalidArgumentException("Console option [{$name}] must be a string when provided.");
        }

        return $value;
    }

    protected function optionalArgumentString(string $name): ?string
    {
        $value = $this->argument($name);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new \InvalidArgumentException("Console argument [{$name}] must be a string when provided.");
        }

        return $value;
    }

    protected function optionInt(string $name, int $default): int
    {
        $value = $this->option($name);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
