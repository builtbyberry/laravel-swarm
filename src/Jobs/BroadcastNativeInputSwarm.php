<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

/**
 * Capability marker for queued broadcasts that require the native-input v1 reader.
 *
 * @internal
 */
final class BroadcastNativeInputSwarm extends BroadcastSwarm {}
