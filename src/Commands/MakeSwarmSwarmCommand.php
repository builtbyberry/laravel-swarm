<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\DetectsInteractiveConsole;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

use function Laravel\Prompts\select;

/**
 * Scaffold a swarm class wired for a chosen topology.
 *
 * Produces output under `app/Ai/Swarms/`. The generated class shape mirrors
 * the runnable starter examples shipped under `stubs/examples/` so what you
 * generate looks like what `swarm:install:examples` lands.
 *
 * Companion command: `make:swarm:agent` scaffolds the individual agent
 * classes a swarm composes. See `docs/generators.md`.
 */
#[AsCommand(name: 'make:swarm:swarm')]
class MakeSwarmSwarmCommand extends GeneratorCommand
{
    use DetectsInteractiveConsole;
    use ResolvesStringConsoleInput;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:swarm:swarm';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new swarm class (sequential, parallel, hierarchical, or static-hierarchical)';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Swarm';

    /**
     * Valid topology values for the --topology option.
     *
     * @var array<int, string>
     */
    protected const TOPOLOGIES = [
        'sequential',
        'parallel',
        'hierarchical',
        'static-hierarchical',
    ];

    /**
     * The resolved topology, set during handle() before stub resolution.
     */
    protected string $resolvedTopology = 'sequential';

    /**
     * Validate the --topology option and resolve the stub before generating.
     */
    public function handle(): ?bool
    {
        if (! $this->resolveTopology()) {
            return true;
        }

        return parent::handle();
    }

    /**
     * Resolve and validate the swarm topology from the --topology option, an
     * interactive prompt, or the non-interactive default. Sets
     * {@see self::$resolvedTopology} and returns true on success; on an invalid
     * value it reports the error and returns false so the caller can abort.
     */
    protected function resolveTopology(): bool
    {
        $topology = $this->optionalOptionString('topology');

        if ($topology === null) {
            $topology = $this->consoleCanPrompt()
                ? $this->promptForTopology()
                // Cannot prompt: use the safe default. The guard checks stdin is
                // a real terminal, not just that Symfony marked the input
                // interactive — see DetectsInteractiveConsole. Trusting the
                // input alone let `Artisan::call()` reach the Prompts fallback,
                // which blocks forever on a stdin that never becomes readable
                // rather than failing loudly (#449).
                : 'sequential';
        }

        if (! in_array($topology, self::TOPOLOGIES, true)) {
            $this->error(
                'Invalid topology ['.$topology.']. Valid options are: '.implode(', ', self::TOPOLOGIES).'.'
            );

            return false;
        }

        $this->resolvedTopology = $topology;

        return true;
    }

    /**
     * Prompt interactively for the swarm topology.
     */
    protected function promptForTopology(): string
    {
        $chosen = select(
            label: 'Which topology?',
            options: [
                'sequential' => 'Sequential — agents in order, each receives prior output',
                'parallel' => 'Parallel — agents concurrent, each gets the original task',
                'hierarchical' => 'Hierarchical — coordinator returns a DAG route plan at runtime',
                'static-hierarchical' => 'Static hierarchical — PHP-defined route plan, no coordinator call',
            ],
            default: 'sequential',
            hint: 'Sequential is the most common starting point.',
        );

        return is_string($chosen) ? $chosen : 'sequential';
    }

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
     * `stubs/swarm.<topology>.stub` into the project root.
     */
    protected function resolveStubPath(): string
    {
        $stubFile = match ($this->resolvedTopology) {
            'parallel' => 'swarm.parallel.stub',
            'hierarchical' => 'swarm.hierarchical.stub',
            'static-hierarchical' => 'swarm.static-hierarchical.stub',
            default => 'swarm.stub',
        };

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
        return $rootNamespace.'\Ai\Swarms';
    }

    /**
     * Get the console command options.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['topology', 't', InputOption::VALUE_OPTIONAL, 'The topology for the swarm (sequential, parallel, hierarchical, static-hierarchical)', null],
        ];
    }
}
