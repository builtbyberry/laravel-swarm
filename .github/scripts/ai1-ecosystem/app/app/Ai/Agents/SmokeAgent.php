<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Provider('openai')]
#[Model('gpt-4.1-mini')]
final class SmokeAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Return the requested smoke result.';
    }
}
