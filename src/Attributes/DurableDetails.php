<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class DurableDetails
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(public readonly array $details) {}
}
