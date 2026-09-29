<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

/**
 * Which terminal outcome a persisted workflow callback answers to.
 *
 * A `then` callback fires only when the run settles as completed; a `catch`
 * callback fires only when the run settles as a failure. Neither fires on
 * cancellation. The string values are the persisted contract stored in the
 * swarm_callback_deliveries `slot` column and must never change.
 */
enum CallbackSlot: string
{
    case Then = 'then';
    case Catch = 'catch';
}
