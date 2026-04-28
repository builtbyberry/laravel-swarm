<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

enum ExecutionMode: string
{
    case Run = 'run';
    case Queue = 'queue';
    case Stream = 'stream';
    case Durable = 'durable';
}
