<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

/**
 * PHPStan-only type aliases imported elsewhere via {@see \phpstan-import-type}.
 * This class is not used at runtime.
 *
 * @phpstan-type SwarmTaskInput string|array<string, mixed>|RunContext
 * @phpstan-type SwarmAssertTask string|array<string, mixed>|callable
 * @phpstan-type SwarmFakeResponses array<int, string>|callable|null
 * @phpstan-type SwarmBroadcastChannels \Illuminate\Broadcasting\Channel|array<int, \Illuminate\Broadcasting\Channel|string>
 * @phpstan-type SwarmStructuredSubset array<string, mixed>
 * @phpstan-type LaravelAiAgentAttachments list<array<string, mixed>>
 * @phpstan-type LaravelAiAgentProvider \Laravel\Ai\Enums\Lab|array<string, mixed>|string|null
 */
final class PhpStanTypeAliases
{
    private function __construct() {}
}
