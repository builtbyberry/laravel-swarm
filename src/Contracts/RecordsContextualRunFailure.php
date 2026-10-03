<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use Throwable;

/** @internal */
interface RecordsContextualRunFailure
{
    /** @param  array<string, mixed>  $metadata */
    public function failWithMetadata(string $runId, Throwable $exception, array $metadata, int $ttlSeconds): void;
}
