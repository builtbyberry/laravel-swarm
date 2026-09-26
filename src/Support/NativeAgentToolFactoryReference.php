<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

final readonly class NativeAgentToolFactoryReference
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $factory,
        public array $arguments = [],
    ) {
        if (trim($factory) === '') {
            throw new SwarmException('Native agent tool factory references require a registered identifier.');
        }

        PlainData::array($arguments, 'native agent tool factory arguments');
    }
}
