<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

enum NativeProtocolProjection: string
{
    case Workflow = 'workflow';

    case FinalAgent = 'final_agent';
}
