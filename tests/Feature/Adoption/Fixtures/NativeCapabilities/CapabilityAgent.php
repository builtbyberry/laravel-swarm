<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

/**
 * A plain application-owned native Laravel AI agent that participates in a Swarm
 * workflow and calls native capability tools during its tool loop.
 *
 * Deliberately Promptable (not ScriptedAgent): the real text gateway drives the
 * loop over faked wire ({@see NativeCapabilityWire}), so the application tools
 * genuinely execute and their nested native modality calls really run.
 */
#[Provider('openai')]
#[Model('gpt-4.1-mini')]
class CapabilityAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * @param  array<int, Tool>  $tools
     */
    public function __construct(
        private array $tools = [],
        private string $instructions = 'Native capability workflow worker.',
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return $this->tools;
    }
}
