<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

/** Optional preflight for citation-aware persistence implementations. */
interface ChecksCitationStorage
{
    public function assertCitationStorageReady(): void;
}
