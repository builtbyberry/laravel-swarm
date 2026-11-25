<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming;

use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * A laravel/ai tool-input stream event (`tool_input_start`, `tool_input_delta`,
 * `tool_input_error`) for any installed laravel/ai. From v1.4.2 it builds the
 * real upstream class; before that it stands in with the same wire shape, which
 * is what Swarm recognises the events by. Class names are built at runtime so the
 * fixture never references a class the minimum laravel/ai lacks.
 */
final class WireToolInputEvent extends StreamEvent
{
    /** @param array<string, mixed> $payload */
    private function __construct(private array $payload) {}

    public static function start(string $id, string $toolId, string $toolName, int $timestamp): StreamEvent
    {
        return self::make('Start', [$id, $toolId, $toolName, $timestamp], [
            'id' => $id, 'type' => 'tool_input_start', 'tool_id' => $toolId, 'tool_name' => $toolName, 'timestamp' => $timestamp,
        ]);
    }

    public static function delta(string $id, string $toolId, string $delta, int $timestamp): StreamEvent
    {
        return self::make('Delta', [$id, $toolId, $delta, $timestamp], [
            'id' => $id, 'type' => 'tool_input_delta', 'tool_id' => $toolId, 'delta' => $delta, 'timestamp' => $timestamp,
        ]);
    }

    public static function error(string $id, string $toolId, string $toolName, string $input, string $error, int $timestamp): StreamEvent
    {
        return self::make('Error', [$id, $toolId, $toolName, $input, $error, $timestamp], [
            'id' => $id, 'type' => 'tool_input_error', 'tool_id' => $toolId, 'tool_name' => $toolName,
            'input' => $input, 'error' => $error, 'timestamp' => $timestamp,
        ]);
    }

    public static function upstreamAvailable(): bool
    {
        return class_exists(self::upstreamClass('Start'));
    }

    /**
     * @param  list<mixed>  $arguments
     * @param  array<string, mixed>  $payload
     */
    private static function make(string $suffix, array $arguments, array $payload): StreamEvent
    {
        $class = self::upstreamClass($suffix);

        if (class_exists($class)) {
            $event = new $class(...$arguments);

            if ($event instanceof StreamEvent) {
                return $event;
            }
        }

        return new self($payload);
    }

    private static function upstreamClass(string $suffix): string
    {
        return implode('\\', ['Laravel', 'Ai', 'Streaming', 'Events', 'ToolInput'.$suffix]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->payload, 'invocation_id' => $this->invocationId];
    }
}
