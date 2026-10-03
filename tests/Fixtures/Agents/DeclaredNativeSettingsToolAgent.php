<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Tools\NativeSettingsTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

final class DeclaredNativeSettingsToolAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Test explicit native tool replacement.';
    }

    public function tools(): iterable
    {
        return [new NativeSettingsTool('declared')];
    }
}
