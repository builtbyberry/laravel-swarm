<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/** Optional fail-before-provider preflight for native-result persistence. */
interface ChecksNativeStepResultStorage
{
    public function assertNativeStepResultStorageReady(): void;
}
