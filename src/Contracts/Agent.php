<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use Laravel\Ai\Contracts\Agent as LaravelAiAgent;

/**
 * Legacy Swarm-owned alias for {@see LaravelAiAgent}.
 *
 * **Implementing this is no longer necessary.** Swarm type-hints the vendor
 * `Laravel\Ai\Contracts\Agent` at every public entry point and runner gate, so
 * any Laravel AI agent works with Swarm unchanged. This interface remains only
 * so classes written against it since v0.5.0 keep working; it adds no methods
 * and grants no capability.
 *
 * Interface inheritance runs one way. Because this extends the vendor contract,
 * every class implementing *this* is a vendor agent — but a class implementing
 * only the vendor contract was never an instance of this. Swarm's surfaces used
 * to demand this interface, which is why plain `laravel/ai` agents were rejected
 * outright. Widening those surfaces to the vendor contract fixed that; this
 * alias is what remains. See the v0.23.0 entry in UPGRADING.md.
 *
 * It also does not insulate consumers from `laravel/ai` pre-1.0 churn, despite
 * once claiming to: it `extends` the vendor interface, so any signature change
 * upstream propagates through it verbatim.
 *
 * New code should type-hint `Laravel\Ai\Contracts\Agent` directly.
 *
 * @deprecated since v0.23.0. Type-hint or implement `Laravel\Ai\Contracts\Agent`
 *             instead. Slated for removal in v1.0.
 */
interface Agent extends LaravelAiAgent {}
