<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;

#[Provider('openai')]
#[Model('gpt-4.1-mini')]
class NativeChildAgent extends NativeHttpAgent
{
    public function instructions(): string
    {
        return 'child-native-parity';
    }

    public function tools(): iterable
    {
        return [];
    }
}
