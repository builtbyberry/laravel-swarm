<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

final readonly class NativeAgentToolReference
{
    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $class,
        public array $arguments = [],
    ) {
        if (trim($class) === '') {
            throw new SwarmException('Native agent tool references require a non-empty class name.');
        }

        PlainData::array($arguments, 'native agent tool constructor arguments');
    }

    /** @return array{class: string, arguments: array<string, mixed>} */
    public function toArray(): array
    {
        return ['class' => $this->class, 'arguments' => $this->arguments];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (! is_string($payload['class'] ?? null) || ! class_exists($payload['class']) || ! is_array($payload['arguments'] ?? null)) {
            throw new SwarmException('Native agent tool reference is invalid.');
        }

        return new self($payload['class'], PlainData::array($payload['arguments'], 'native agent tool constructor arguments'));
    }
}
