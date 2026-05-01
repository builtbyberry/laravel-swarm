<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class DurableRetry
{
    /**
     * @param  array<int, int>  $backoffSeconds
     * @param  array<int, class-string<\Throwable>>  $nonRetryable
     */
    public function __construct(
        public readonly int $maxAttempts = 1,
        public readonly array $backoffSeconds = [],
        public readonly array $nonRetryable = [],
    ) {}
}
