<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmInputGuardrail;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

final class TaggingInputGuardrail implements SwarmInputGuardrail
{
    /** @var list<string> */
    public static array $log = [];

    public static function resetLog(): void
    {
        self::$log = [];
    }

    public function __construct(private string $tag) {}

    public function validate(RunContext $context): void
    {
        self::$log[] = $this->tag;
    }
}
