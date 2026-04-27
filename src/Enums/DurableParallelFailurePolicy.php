<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

enum DurableParallelFailurePolicy: string
{
    case CollectFailures = 'collect_failures';
    case FailRun = 'fail_run';
    case PartialSuccess = 'partial_success';
}
