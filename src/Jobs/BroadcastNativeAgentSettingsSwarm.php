<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

/** @internal Capability marker for queued broadcasts that require the native-settings v2 reader. */
final class BroadcastNativeAgentSettingsSwarm extends BroadcastSwarm {}
