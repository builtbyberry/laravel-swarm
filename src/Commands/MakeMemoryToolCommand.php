<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\DetachesUnanswerableStdin;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use Composer\InstalledVersions;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Scaffold a custom Swarm memory tool.
 *
 * Produces output under `app/Ai/Tools/`, mirroring the `app/Ai/` namespace
 * Laravel AI establishes and the v0.8 starter-example conventions. The
 * generated class extends one of the shipped memory tools — `Recall` or
 * `Remember` (#128) — so a custom tool has the exact same shape as the
 * framework's own: scope ids resolve from the active run, reads honour the
 * propagation policy, and writes flow through the capture policy.
 *
 * Flags:
 *  - `--scope=run|conversation|agent|swarm` seeds the tool's default scope.
 *  - `--base=recall|remember` chooses which shipped tool shape to extend
 *    (a read tool or a write tool). Defaults to `recall`.
 *  - `--vector` scaffolds a semantic, vector-aware tool — but only when the
 *    `builtbyberry/laravel-swarm-memory-vector` companion is installed.
 *
 * Companion command: `make:swarm:agent` scaffolds the agents that compose a
 * swarm. See `docs/generators.md`.
 */
#[AsCommand(name: 'make:memory-tool')]
class MakeMemoryToolCommand extends GeneratorCommand
{
    use DetachesUnanswerableStdin;
    use ResolvesStringConsoleInput;

    /**
     * The Composer package name of the vector companion.
     */
    protected const VECTOR_PACKAGE = 'builtbyberry/laravel-swarm-memory-vector';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:memory-tool';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a custom Swarm memory tool (scaffolds a Recall/Remember subclass for an agent)';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Tool';

    /**
     * The resolved default scope, set during handle() before stub resolution.
     */
    protected MemoryScope $resolvedScope = MemoryScope::Run;

    /**
     * The shipped tool the generated class extends ('Recall' or 'Remember').
     */
    protected string $resolvedBase = 'Recall';

    /**
     * Whether a vector-aware tool was requested.
     */
    protected bool $vector = false;

    /**
     * Validate the options and resolve scope/base/stub before generating.
     *
     * Return convention follows `GeneratorCommand::handle()` (`?bool`, matching
     * the `make:swarm:*` generators): a validation failure returns `true`, which
     * Laravel casts to a non-zero exit code (`(int) true === 1`) — i.e. `true`
     * here means "stop, failed", not "succeeded". The success path defers to
     * `parent::handle()`.
     */
    public function handle(): ?bool
    {
        $scope = $this->optionalOptionString('scope');

        if ($scope !== null) {
            $resolvedScope = MemoryScope::tryFrom($scope);

            if ($resolvedScope === null) {
                $this->error(
                    'Invalid scope ['.$scope.']. Valid options are: '.$this->scopeList().'.'
                );

                return true;
            }

            $this->resolvedScope = $resolvedScope;
        }

        $base = Str::lower($this->optionalOptionString('base') ?? 'recall');

        if (! in_array($base, ['recall', 'remember'], true)) {
            $this->error('Invalid base ['.$base.']. Valid options are: recall, remember.');

            return true;
        }

        $this->resolvedBase = Str::studly($base);

        $this->vector = (bool) $this->option('vector');

        if ($this->vector && ! $this->vectorCompanionInstalled()) {
            $this->error(
                'The --vector flag requires the '.self::VECTOR_PACKAGE.' companion package, '
                .'which is not installed. Install it with: composer require '.self::VECTOR_PACKAGE
            );

            return true;
        }

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
     *
     * Allows applications to override the shipped stub by publishing
     * `stubs/swarm.memory-tool[.vector].stub` into the project root.
     */
    protected function resolveStubPath(): string
    {
        $stubFile = $this->vector
            ? 'swarm.memory-tool.vector.stub'
            : 'swarm.memory-tool.stub';

        return file_exists($customPath = $this->laravel->basePath("stubs/{$stubFile}"))
            ? $customPath
            : __DIR__."/../../stubs/{$stubFile}";
    }

    /**
     * Replace the memory-tool placeholders before handing back to the parent.
     *
     * @param  string  $name
     */
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $className = class_basename($name);

        return str_replace(
            ['{{ baseTool }}', '{{ scopeCase }}', '{{ toolName }}'],
            [$this->resolvedBase, $this->resolvedScope->name, $this->toolName($className)],
            $stub,
        );
    }

    /**
     * Derive the model-facing tool name from the class name (snake_case).
     */
    protected function toolName(string $className): string
    {
        return Str::snake($className);
    }

    /**
     * Get the default namespace for the class.
     *
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Ai\Tools';
    }

    /**
     * Determine whether the vector companion package is installed.
     */
    protected function vectorCompanionInstalled(): bool
    {
        return class_exists(InstalledVersions::class)
            && InstalledVersions::isInstalled(self::VECTOR_PACKAGE);
    }

    /**
     * A human-readable list of the valid scope values.
     */
    protected function scopeList(): string
    {
        return implode(', ', array_map(
            static fn (MemoryScope $case): string => $case->value,
            MemoryScope::cases(),
        ));
    }

    /**
     * Get the console command options.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['scope', 's', InputOption::VALUE_OPTIONAL, 'Default memory scope for the tool (run, conversation, agent, swarm)', null],
            ['base', 'b', InputOption::VALUE_OPTIONAL, 'The shipped tool shape to extend (recall, remember)', 'recall'],
            ['vector', null, InputOption::VALUE_NONE, 'Scaffold a vector-aware tool (requires the laravel-swarm-memory-vector companion)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the tool already exists'],
        ];
    }
}
