<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

/**
 * The outbox lane a `swarm:relay` invocation drains.
 *
 * The relay drains three independent outboxes: the durable outbox (queues the
 * jobs that advance durable runs), the audit outbox (re-emits failed audit
 * evidence through the bound sink), and the callback outbox (dispatches terminal
 * workflow then/catch deliveries). A lane names which of those a request targets;
 * the granular durable dispatch types live in {@see DurableDispatchType}.
 *
 * Extensible: future outboxes add a case here rather than overloading the
 * durable dispatch types.
 */
enum RelayLane: string
{
    case Durable = 'durable';
    case Audit = 'audit';
    case Callback = 'callback';
}
