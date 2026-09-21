<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\AgentTool;

#[Provider('openai')]
#[Model('gpt-4.1-mini')]
class NativeHttpAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'parent-native-parity';
    }

    public function tools(): iterable
    {
        return config('tests.native_parity.tool') === 'child'
            ? [new AgentTool(new NativeChildAgent)]
            : [new NativeTool];
    }
}
