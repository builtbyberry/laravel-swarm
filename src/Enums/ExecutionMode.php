<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

enum ExecutionMode: string
{
    case Sync = 'sync';

    case Queued = 'queued';

    case Mixed = 'mixed';
}
