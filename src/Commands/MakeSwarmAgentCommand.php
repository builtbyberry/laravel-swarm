<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\DetachesUnanswerableStdin;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Scaffold a swarm agent class.
 *
 * Produces output under `app/Ai/Agents/`. The generated class extends
 * `ScriptedAgent` so it runs end-to-end with no provider configured — the
 * same shape used by the runnable starter examples shipped under
 * `stubs/examples/`. A TODO marker in `reply()` and the docblock both point
 * at the swap-to-Promptable upgrade path for plugging in a real LLM.
 *
 * Companion command: `make:swarm:swarm` scaffolds the swarm class that
 * composes one or more agents. See `docs/generators.md`.
 */
#[AsCommand(name: 'make:swarm:agent')]
class MakeSwarmAgentCommand extends GeneratorCommand
{
    use DetachesUnanswerableStdin;
    use ResolvesStringConsoleInput;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:swarm:agent';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new swarm agent class (scaffolds a ScriptedAgent ready to swap for a real LLM)';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Agent';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath();
    }

    /**
     * Resolve the fully-qualified path to the stub.
     *
     * Allows applications to override the shipped stub by publishing
     * `stubs/swarm.agent.stub` into the project root.
     */
    protected function resolveStubPath(): string
    {
        $stubFile = 'swarm.agent.stub';

        return file_exists($customPath = $this->laravel->basePath("stubs/{$stubFile}"))
            ? $customPath
            : __DIR__."/../../stubs/{$stubFile}";
    }

    /**
     * Get the default namespace for the class.
     *
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Ai\Agents';
    }
}
