<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\PausedWait;

use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableLifecycleController;

class RetryDurableLifecycleController extends DurableLifecycleController
{
    /** @var list<int> */
    public array $backoffs = [];

    protected function backoffResumeConcurrencyRetry(int $attempt): void
    {
        $this->backoffs[] = $attempt;
    }
}
