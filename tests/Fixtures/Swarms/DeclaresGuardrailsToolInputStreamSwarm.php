<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\DefinesGuardrails;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ToolInputStreamEditor;

/**
 * {@see ToolInputStreamSwarm} with a guardrails() hook. The hook is asked again at
 * validation time, so whatever it returns when the run starts (`$outputGuardrails`,
 * empty by default) does not settle whether the answer can still be rejected.
 */
#[Topology(TopologyEnum::Sequential)]
class DeclaresGuardrailsToolInputStreamSwarm implements DefinesGuardrails, Swarm
{
    use Runnable;

    /** @var list<mixed> */
    public static array $outputGuardrails = [];

    public function agents(): array
    {
        return [new ToolInputStreamEditor];
    }

    public function guardrails(): array
    {
        return self::$outputGuardrails;
    }
}
