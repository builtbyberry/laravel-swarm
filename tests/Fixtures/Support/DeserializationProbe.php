<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support;

/**
 * Test-only stand-in for an object-injection gadget: it records every time
 * unserialize() constructs it, so a test can prove a payload was rejected
 * without the object ever being woken.
 */
final class DeserializationProbe
{
    public static int $woken = 0;

    public function __wakeup(): void
    {
        self::$woken++;
    }

    /**
     * The serialized form, written by hand so producing it never instantiates the probe.
     */
    public static function wire(): string
    {
        return sprintf('O:%d:"%s":0:{}', strlen(self::class), self::class);
    }
}
