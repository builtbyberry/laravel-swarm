<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use Laravel\Ai\Contracts\HasStructuredOutput as LaravelAiHasStructuredOutput;

/**
 * Swarm-owned marker interface for agents that produce structured output.
 *
 * This contract extends `Laravel\Ai\Contracts\HasStructuredOutput` so existing
 * structured-output agents continue to satisfy it without changes. Routing and
 * runner code that needs to assert structured-output capability should depend
 * on this interface rather than the vendor contract directly.
 */
interface HasStructuredOutput extends LaravelAiHasStructuredOutput {}
