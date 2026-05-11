<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms;

use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\DefinesGuardrails;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\TaggingInputGuardrail;

#[Topology(TopologyEnum::Sequential)]
final class GuardrailMergeParentSwarm implements DefinesGuardrails, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new FakeResearcher,
        ];
    }

    public function guardrails(): array
    {
        return [
            new TaggingInputGuardrail('parent'),
        ];
    }
}
