<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

final class PayloadLimitResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $value,
        public readonly array $metadata = [],
    ) {}
}
