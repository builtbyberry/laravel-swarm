<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use RuntimeException;

/**
 * Rolls back a branch lease taken for a branch the fan-out cap is holding.
 * Never escapes DurableBranchAdvancer.
 *
 * @internal
 */
final class DurableBranchHeldException extends RuntimeException {}
