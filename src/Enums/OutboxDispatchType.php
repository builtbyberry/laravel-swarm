<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

enum OutboxDispatchType: string
{
    case Step = 'step';
    case Branch = 'branch';
    case QueuedResume = 'queued_resume';
}
