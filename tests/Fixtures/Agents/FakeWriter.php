<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Laravel\Ai\Promptable;

class FakeWriter implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a writer.';
    }
}
