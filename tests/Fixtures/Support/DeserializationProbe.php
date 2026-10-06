<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Support;

/**
 * Test-only stand-in for an object-injection gadget: it records every time
 * unserialize() constructs it, and every time smuggled code calls it, so a test
 * can prove a payload was rejected without anything in it being woken or run.
 */
final class DeserializationProbe
{
    public static int $woken = 0;

    public static int $executed = 0;

    public function __wakeup(): void
    {
        self::$woken++;
    }

    /**
     * Called from code smuggled into a forged closure body.
     */
    public static function execute(): null
    {
        self::$executed++;

        return null;
    }

    public static function reset(): void
    {
        self::$woken = 0;
        self::$executed = 0;
    }

    /**
     * The serialized form, written by hand so producing it never instantiates the probe.
     */
    public static function wire(): string
    {
        return sprintf('O:%d:"%s":0:{}', strlen(self::class), self::class);
    }
}
