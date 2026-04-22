<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class FakeEditor implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are an editor.';
    }
}
