<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming;

/**
 * A stream event type no runner's instanceof chain recognizes, standing in for
 * a future laravel/ai event the bump might surface.
 *
 * It deliberately carries a content-bearing payload ($secret) so the breadcrumb
 * test can assert the degrade-safe log records only the event class, never the
 * body — the class-only redaction discipline the runners must hold.
 */
final class UnknownStreamEvent
{
    public function __construct(
        public readonly string $id = 'unknown-1',
        public readonly string $secret = 'super-secret-unredacted-payload',
    ) {}
}
