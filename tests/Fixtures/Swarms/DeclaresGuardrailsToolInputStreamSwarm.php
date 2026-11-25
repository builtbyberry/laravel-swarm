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
 * {@see ToolInputStreamSwarm} with a guardrails() hook that returns nothing when the
 * run starts: the hook is asked again at validation time, so it may add an output
 * guardrail by then ($outputGuardrails is set mid-run by a test).
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
