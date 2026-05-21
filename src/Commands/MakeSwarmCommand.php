<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Backwards-compatibility alias for the new dedicated generator commands.
 *
 * `make:swarm` was the only generator the package shipped through v0.7.x;
 * it scaffolded a swarm class. v0.8.0 splits the generator surface in two:
 *
 *  - `make:swarm:swarm <Name>` — scaffold a swarm class (this command's
 *    historical behavior, polished and unchanged by default)
 *  - `make:swarm:agent <Name>` — scaffold an agent class (new in v0.8.0)
 *
 * `make:swarm` continues to work and delegates to `make:swarm:swarm` with
 * the same arguments so existing scripts and docs keep functioning. A
 * deprecation notice prints to stderr (so it never contaminates piped JSON
 * or stdout) to nudge callers toward the new commands.
 *
 * Slated for removal in a future major release; track #91 for the
 * deprecation window.
 */
#[AsCommand(name: 'make:swarm')]
class MakeSwarmCommand extends MakeSwarmSwarmCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:swarm';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[Deprecated] Alias for make:swarm:swarm. Use make:swarm:swarm or make:swarm:agent instead.';

    /**
     * Print a deprecation notice, then delegate to the new command's logic.
     */
    public function handle(): ?bool
    {
        $this->components->warn(
            'make:swarm is deprecated. Use `make:swarm:swarm` to scaffold a swarm class or `make:swarm:agent` to scaffold an agent class. See docs/generators.md.'
        );

        return parent::handle();
    }
}
