<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

/**
 * @internal
 */
final readonly class PayloadLimitResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $value,
        public array $metadata = [],
    ) {}
}
