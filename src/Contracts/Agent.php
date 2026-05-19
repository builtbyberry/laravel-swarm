<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use Laravel\Ai\Contracts\Agent as LaravelAiAgent;

/**
 * Swarm-owned marker interface for agents.
 *
 * This contract extends the underlying `Laravel\Ai\Contracts\Agent` interface
 * so every existing Laravel AI agent implementation satisfies it without
 * changes. Type-hinting against this contract from Swarm's public surface
 * shields consumers from churn in the `laravel/ai` pre-1.0 contract while
 * preserving full interoperability with the vendor ecosystem.
 *
 * No methods are added here; this is intentionally a pass-through marker so
 * the swap is invisible at runtime.
 */
interface Agent extends LaravelAiAgent {}
