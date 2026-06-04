<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Test policy that redacts memory writes. With no keys configured it redacts
 * every write (usable as a config-bound "redact everything" policy); pass a key
 * list to redact only those keys and write the rest through untouched.
 */
final class RedactingMemoryCapturePolicy implements MemoryCapturePolicy
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
            return CaptureDecision::Redact;
        }

        return CaptureDecision::Full;
    }
}
