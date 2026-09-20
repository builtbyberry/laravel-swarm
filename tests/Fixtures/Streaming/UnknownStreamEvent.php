<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming;

use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * A synthetic native event with a deliberately unknown type.
 *
 * It deliberately carries a content-bearing payload ($secret) so the breadcrumb
 * test can assert the degrade-safe log records only the event class, never the
 * body — the class-only redaction discipline the runners must hold.
 */
final class UnknownStreamEvent extends StreamEvent
{
    public function __construct(
        public readonly string $id = 'unknown-1',
        public readonly string $secret = 'super-secret-unredacted-payload',
    ) {}

    public function toArray(): array
    {
        return ['type' => 'unknown.fixture', 'id' => $this->id, 'secret' => $this->secret];
    }
}
