<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\ProviderTools\Fixtures;

use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

class ProviderSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new ProviderAgent];
    }
}
