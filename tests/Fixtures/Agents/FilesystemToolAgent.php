<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Concerns\HasSwarmFilesystemTools;
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * A minimal agent that exposes the config-gated Swarm filesystem tools, used to
 * assert the {@see HasSwarmFilesystemTools} gating and disk-binding behavior.
 */
class FilesystemToolAgent implements Agent, HasTools
{
    use HasSwarmFilesystemTools;
    use Promptable;

    public function instructions(): string
    {
        return 'You can read and write files on the configured sandbox disk.';
    }

    /**
     * @return array<int, \Laravel\Ai\Contracts\Tool>
     */
    public function tools(): iterable
    {
        return $this->swarmFilesystemTools();
    }
}
