<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

use BuiltByBerry\LaravelSwarm\Responses\DurableCancelResult;
use BuiltByBerry\LaravelSwarm\Responses\DurablePauseResult;
use BuiltByBerry\LaravelSwarm\Responses\DurableResumeResult;

/**
 * The effective status a durable lifecycle control verb transitioned a run into.
 *
 * A pause/cancel either applies immediately (the run was idle at a checkpoint)
 * or is scheduled for the run's next safe boundary (the run was mid-step). A
 * resume either re-dispatches the next step or resumes back into a waiting
 * boundary. This enum backs the {@see DurablePauseResult},
 * {@see DurableResumeResult}, and
 * {@see DurableCancelResult} value objects
 * so the effective status is a typed public value, not a raw string.
 */
enum DurableLifecycleStatus: string
{
    case Paused = 'paused';
    case PauseScheduled = 'pause_scheduled';
    case Cancelled = 'cancelled';
    case CancelScheduled = 'cancel_scheduled';
    case Resumed = 'resumed';
    case Waiting = 'waiting';
}
