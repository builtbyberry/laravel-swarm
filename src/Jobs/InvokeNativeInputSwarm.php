<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

/**
 * Capability marker for queued runs that require the native-input v1 reader.
 *
 * @internal
 */
final class InvokeNativeInputSwarm extends InvokeSwarm {}
