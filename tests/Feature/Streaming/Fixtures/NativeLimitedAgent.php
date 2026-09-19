<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;

#[Provider('openai')]
#[Model('gpt-4.1-mini')]
#[MaxSteps(1)]
class NativeLimitedAgent extends NativeHttpAgent {}
