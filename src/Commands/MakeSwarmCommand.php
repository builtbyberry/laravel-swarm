<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

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
        return __DIR__.'/../../stubs/swarm.stub';
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
}
