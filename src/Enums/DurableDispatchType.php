<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

/**
 * The kind of durable-run dispatch persisted in a `swarm_durable_outbox` row.
 *
 * Every case flows through `DurableJobDispatcher` when the durable lane is
 * drained. These are the values stored in the outbox `dispatch_type` column and
 * accepted by `swarm:relay --type=…`; the string values are a persisted contract
 * and must never change.
 *
 * The audit lane is not a durable dispatch type — it lives in
 * {@see RelayLane::Audit} and drains a separate outbox.
 */
enum DurableDispatchType: string
{
    case Step = 'step';
    case Branch = 'branch';
    case QueuedResume = 'queued_resume';
}
