<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures;

use BuiltByBerry\LaravelSwarm\Contracts\NativeAgentToolFactory;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentToolReference;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Tools\NativeSettingsTool;

final class NativeSettingsToolFactory implements NativeAgentToolFactory
{
    public static int $calls = 0;

    public function references(array $arguments): iterable
    {
        self::$calls++;

        yield new NativeAgentToolReference(NativeSettingsTool::class, [
            'tenant' => (string) ($arguments['tenant'] ?? 'default'),
        ]);
    }
}
