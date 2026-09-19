<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

/**
 * Contains no approval payload, provider replay state, or chained exception.
 *
 * @internal
 */
class UnsupportedNativeApprovalException extends SwarmException
{
    public function __construct(string $message = 'Native tool approval is not supported by Laravel Swarm. Tools may already have acted; review effects before an operator-controlled restart.')
    {
        parent::__construct($message);
    }
}
