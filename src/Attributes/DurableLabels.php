<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class DurableLabels
{
    /**
     * @param  array<string, bool|int|float|string|null>  $labels
     */
    public function __construct(public readonly array $labels) {}
}
