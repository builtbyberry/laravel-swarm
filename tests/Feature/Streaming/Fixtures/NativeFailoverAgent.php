<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures;

use Laravel\Ai\Attributes\Provider;

#[Provider(['primary' => 'gpt-4.1-mini', 'fallback' => 'gpt-4.1-mini'])]
class NativeFailoverAgent extends NativeHttpAgent {}
