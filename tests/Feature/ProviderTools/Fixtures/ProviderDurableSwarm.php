<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\ProviderTools\Fixtures;

use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;

#[DurableStreaming]
#[DurableRetry(maxAttempts: 2, backoffSeconds: [1])]
class ProviderDurableSwarm extends ProviderSwarm {}
