<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:swarm')]
class MakeSwarmCommand extends GeneratorCommand
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
    protected $description = 'Create a new swarm class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Swarm';

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
        $topology = $this->option('topology');

        $stubFile = match ($topology) {
            'static-hierarchical' => 'swarm.static-hierarchical.stub',
            default               => 'swarm.stub',
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
            ['topology', 't', InputOption::VALUE_OPTIONAL, 'The topology for the swarm (sequential, static-hierarchical)', 'sequential'],
        ];
    }
}
