<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Test policy that drops memory writes. With no keys configured it skips every
 * write; pass a key list to skip only those keys and write the rest through.
 */
final class SkippingMemoryCapturePolicy implements MemoryCapturePolicy
{
    /**
     * @param  array<int, string>  $keys
     */
    public function __construct(
        private array $keys = [],
    ) {}

    public function memory(
        MemoryScope $scope,
        string $key,
        ?RunContext $context = null,
        ?Actor $actor = null,
    ): CaptureDecision {
        if ($this->keys === [] || in_array($key, $this->keys, true)) {
            return CaptureDecision::Skip;
        }

        return CaptureDecision::Full;
    }
}
