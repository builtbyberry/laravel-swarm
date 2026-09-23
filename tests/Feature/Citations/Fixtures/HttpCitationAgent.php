<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class HttpCitationAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Answer with sources.';
    }
}
