<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

enum Topology: string
{
    case Sequential = 'sequential';

    case Parallel = 'parallel';

    case Hierarchical = 'hierarchical';
}
