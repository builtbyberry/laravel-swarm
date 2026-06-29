<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

/**
 * Thrown when a streaming run's hot working set exceeds the budget under a
 * `refuse` growth policy, or breaches the operator hard-cap regardless of the
 * declared policy (#288).
 *
 * This is the single intentional, fail-loud abort of the context-growth
 * governor — distinguishable from any error the governor swallows internally.
 * It extends {@see SwarmException} so it surfaces through the runner's
 * run.failed path and is re-dispatchable for durable runs.
 */
class ContextBudgetExceededException extends SwarmException {}
