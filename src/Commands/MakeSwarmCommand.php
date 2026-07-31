<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

use function Laravel\Prompts\select;

/**
 * Guided front door for the swarm generators (and back-compat alias).
 *
 * `make:swarm` was the only generator the package shipped through v0.7.x;
 * it scaffolded a swarm class. v0.8.0 split the generator surface in two
 * (`make:swarm:swarm` + `make:swarm:agent`), and `make:swarm` became a
 * deprecated alias for `make:swarm:swarm`.
 *
 * v0.22.0 keeps that alias behaviour but layers a guided, interactive flow
 * on top so newcomers have a single friendly entry point. Run it in a real
 * terminal with no flags and it asks what you want to build:
 *
 *  - a **single agent**, whose scaffold demonstrates the `Swarm::agent()`
 *    front door — the full governed pipeline for one agent, no swarm class;
 *  - a **multi-agent swarm**, which then prompts for a topology exactly as
 *    `make:swarm:swarm` does.
 *
 * Everything stays CI-safe: pass `--single`, pass `--topology`, or run
 * non-interactively (`Artisan::call(...)`, `--no-interaction`) and the
 * command never prompts — it falls back to the historical swarm-class path
 * (sequential) unless `--single` is given.
 *
 * A deprecation notice still prints to stderr (so it never contaminates
 * piped JSON or stdout) to nudge callers toward `make:swarm:swarm` /
 * `make:swarm:agent`. Slated for removal in a future major release; track
 * #91 for the deprecation window.
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
    protected $description = '[Deprecated] Guided alias for make:swarm:swarm / make:swarm:agent. Prefer those directly.';

    /**
     * Whether this run scaffolds a single agent (the `Swarm::agent()` path)
     * rather than a swarm class. Resolved in {@see self::handle()} before the
     * generator's stub/namespace/type hooks fire.
     */
    protected bool $single = false;

    /**
     * Print a deprecation notice, resolve whether the caller wants a single
     * agent or a swarm, then delegate to the generator pipeline.
     *
     * Routing the warning through getErrorOutput() keeps stdout clean for
     * callers piping generator output into other tools.
     */
    public function handle(): ?bool
    {
        // getErrorStyle() returns a SymfonyStyle bound to ConsoleOutput::getErrorOutput()
        // in production (writes to stderr) and to the same buffer in tests
        // (which use BufferedOutput). Keeps the warning out of piped stdout.
        $this->output->getErrorStyle()->writeln(
            '<comment>make:swarm is deprecated. Use `make:swarm:swarm` to scaffold a swarm class or `make:swarm:agent` to scaffold an agent class. See docs/generators.md.</comment>'
        );

        $this->single = $this->resolveSingleAgentMode();

        if ($this->single) {
            // Re-point the generator at the agent surface: the success message
            // ("Agent [...] created successfully") and namespace/stub hooks all
            // key off this. The parent's topology resolution is skipped below.
            $this->type = 'Agent';
        }

        return parent::handle();
    }

    /**
     * Decide whether to scaffold a single agent (the `Swarm::agent()` path).
     *
     * Precedence (so scripts and CI never block on a prompt):
     *  1. `--single` forces the single-agent path.
     *  2. An explicit `--topology` means a multi-agent swarm.
     *  3. Interactively (real TTY, no flags), ask the user.
     *  4. Otherwise fall back to the historical swarm-class path.
     */
    protected function resolveSingleAgentMode(): bool
    {
        if ($this->option('single') === true) {
            return true;
        }

        if ($this->optionalOptionString('topology') !== null) {
            return false;
        }

        if (! $this->wantsInteraction()) {
            return false;
        }

        // NOTE: laravel/prompts returns this `default` verbatim in
        // non-interactive contexts (e.g. `Artisan::call(...)`), so it MUST be
        // `swarm` to preserve the historical `make:swarm <Name>` behaviour
        // (scaffold a swarm class). A real TTY still shows the full choice.
        $choice = select(
            label: 'What would you like to scaffold?',
            options: [
                'single' => 'A single agent — run it instantly with Swarm::agent(), no swarm class',
                'swarm' => 'A multi-agent swarm — choose a topology',
            ],
            default: 'swarm',
            hint: 'A single agent still gets the full governed pipeline: audit, guardrails, capture, telemetry.',
        );

        return $choice === 'single';
    }

    /**
     * In single-agent mode there is no topology to resolve, so short-circuit
     * the parent's prompt/validation. Otherwise defer to the swarm flow.
     */
    protected function resolveTopology(): bool
    {
        if ($this->single) {
            return true;
        }

        return parent::resolveTopology();
    }

    /**
     * Single agents land under `app/Ai/Agents`; swarms keep the parent's
     * `app/Ai/Swarms` default.
     *
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        if ($this->single) {
            return $rootNamespace.'\Ai\Agents';
        }

        return parent::getDefaultNamespace($rootNamespace);
    }

    /**
     * Resolve the stub path. In single-agent mode we use the dedicated
     * single-agent stub (which demonstrates the `Swarm::agent()` front door);
     * otherwise defer to the parent's topology-aware stub resolution. Both
     * honour a published `stubs/*.stub` override in the app root.
     */
    protected function resolveStubPath(): string
    {
        if (! $this->single) {
            return parent::resolveStubPath();
        }

        $stubFile = 'swarm.single-agent.stub';

        return file_exists($customPath = $this->laravel->basePath("stubs/{$stubFile}"))
            ? $customPath
            : __DIR__."/../../stubs/{$stubFile}";
    }

    /**
     * Whether the command may prompt: a real interactive TTY and not
     * `--no-interaction`.
     */
    protected function wantsInteraction(): bool
    {
        return $this->input->isInteractive() && $this->option('no-interaction') !== true;
    }

    /**
     * Get the console command options.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['single', null, InputOption::VALUE_NONE, 'Scaffold a single agent (the Swarm::agent() front door) instead of a swarm class'],
        ]);
    }
}
