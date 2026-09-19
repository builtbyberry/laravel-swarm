<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Fixtures\NativeOutcome;

use RuntimeException;

class ContextFailure extends RuntimeException
{
    public function __construct(public mixed $context)
    {
        parent::__construct('context-failure');
    }
}
