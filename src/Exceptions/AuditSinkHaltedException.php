<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

use BuiltByBerry\LaravelSwarm\Contracts\HaltsSwarmExecution;
use Throwable;

/**
 * Thrown by SwarmAuditDispatcher when the bound SinkFailureHandler returns
 * SinkFailureDecision::Halt in response to a sink or signing failure.
 *
 * Carries the HaltsSwarmExecution marker so the runner surfaces it as a
 * deliberate failure rather than letting it propagate as an opaque audit
 * concern. The original sink exception is preserved as $previous.
 *
 * Implementations and operators should not rescue this exception inside
 * agents — it signals that the run must not continue (e.g. cannot emit
 * signed audit evidence in a regulated deployment).
 */
class AuditSinkHaltedException extends SwarmException implements HaltsSwarmExecution
{
    public function __construct(
        public readonly string $category,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'Swarm audit sink halted run while emitting [%s]: %s',
                $category,
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }
}
