<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:swarm')]
class MakeSwarmCommand extends GeneratorCommand
{
    use ResolvesStringConsoleInput;

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
    protected $description = 'Create a new swarm class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Swarm';

    /**
     * The resolved topology, set during handle() before stub resolution.
     */
    protected string $resolvedTopology = 'sequential';

    /**
     * Validate the --topology option before generating.
     */
    public function handle(): ?bool
    {
        $topology = $this->optionalOptionString('topology');
        $valid = ['sequential', 'parallel', 'hierarchical', 'static-hierarchical'];

        if ($topology === null) {
            if ($this->input->isInteractive()) {
                $chosen = $this->choice('Which topology?', ['sequential', 'parallel', 'hierarchical', 'static-hierarchical'], 'sequential');
                $topology = is_string($chosen) ? $chosen : 'sequential';
            } else {
                $topology = 'sequential';
            }
        }

        if (! in_array($topology, $valid, true)) {
            $this->error(
                'Invalid topology ['.$topology.']. Valid options are: '.implode(', ', $valid).'.'
            );

            return true;
        }

        $this->resolvedTopology = $topology;

        return parent::handle();
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
