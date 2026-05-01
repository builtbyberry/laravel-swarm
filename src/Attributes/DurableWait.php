<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class DurableWait
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $timeout = null,
        public readonly ?string $reason = null,
    ) {}
}
