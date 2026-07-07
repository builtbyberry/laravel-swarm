<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\InteractsWithBlueprintCorpus;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesHostRootNamespace;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Scaffold a complete, runnable swarm from a curated blueprint — renamed to the
 * developer's chosen name.
 *
 * Where `make:swarm:swarm` scaffolds a single empty swarm shell for a topology,
 * this scaffolds a whole working use-case: the swarm class, its agents, and a
 * runnable console command, wired together and ready to run. It draws from the
 * same curated corpus under `stubs/examples/` that `swarm:install:examples`
 * lands — but where the installer copies a tree **verbatim** (fixed-name
 * reference material to read), this copies it **renamed** (a starting point to
 * make your own).
 *
 * Each blueprint declares its canonical class/command names in a per-tree
 * `blueprint.json` manifest; the rename substitutes those tokens for names
 * derived from the developer's argument. Descriptive agent class names are
 * deliberately preserved — a single swarm name cannot sensibly rename three
 * distinct scout agents, and keeping them keeps the generated code readable.
 *
 * Companion commands: `make:swarm:swarm` (empty shell), `make:swarm:agent`
 * (a single agent). See `docs/generators.md`.
 *
 * Programmatic callers (an umbrella command, a test, `Artisan::call`) should
 * pass `--no-interaction` — like every other prompting command in the package,
 * the interactive picker/name prompts only fire when the input is interactive.
 */
#[AsCommand(name: 'make:swarm:blueprint')]
class MakeSwarmBlueprintCommand extends Command
{
    use InteractsWithBlueprintCorpus;
    use ResolvesHostRootNamespace;

    /**
     * Names that are PHP reserved words (illegal as a class name) or collide
     * with a core type the generated swarm imports (`Swarm`, `Agent`) — either
     * would produce a file that does not compile. Compared case-insensitively.
     *
     * @var list<string>
     */
    private const RESERVED_NAMES = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'do', 'echo',
        'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif',
        'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'final',
        'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'instanceof', 'insteadof', 'interface', 'isset',
        'list', 'match', 'namespace', 'new', 'or', 'print', 'private', 'protected',
        'public', 'readonly', 'require', 'return', 'static', 'switch', 'throw',
        'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
        'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void',
        'iterable', 'object', 'mixed', 'never', 'self', 'parent',
        'swarm', 'agent',
    ];

    /** @var string */
    protected $signature = 'make:swarm:blueprint
        {name? : The name for your swarm (StudlyCase, e.g. SupportTriage)}
        {--template= : The blueprint to scaffold from (slug — omit to pick interactively)}
        {--without-command : Do not scaffold the runnable console command}
        {--force : Overwrite files that already exist in the host app}';

    /** @var string */
    protected $description = 'Scaffold a complete, runnable swarm from a curated blueprint, renamed as your own.';

    public function handle(Filesystem $files): int
    {
        $blueprints = $this->discoverBlueprints($files);

        if ($blueprints === []) {
            $this->components->error('No blueprints are available in the package.');

            return self::FAILURE;
        }

        $slug = $this->resolveTemplate($blueprints);

        if ($slug === null) {
            return self::FAILURE;
        }

        $name = $this->resolveName();

        if ($name === null) {
            return self::FAILURE;
        }

        ['manifest' => $manifest, 'dir' => $treeDir] = $blueprints[$slug];

        $renames = $this->buildRenameMap($manifest['tokens'], $name);

        $includeCommand = ! (bool) $this->option('without-command');
        if ($includeCommand && $this->wantsInteraction()) {
            $includeCommand = confirm(
                label: 'Scaffold the runnable console command too?',
                default: true,
                hint: 'A `php artisan` command that runs your swarm end-to-end.',
            );
        }

        $pairs = $this->collectCopyPairs($treeDir);

        if (! $includeCommand) {
            $pairs = array_values(array_filter(
                $pairs,
                static fn (array $pair): bool => ! str_starts_with($pair['relative'], 'app/Console/Commands/'),
            ));
        }

        $rootNamespace = $this->resolveRootNamespace($files);
        $force = (bool) $this->option('force');

        $plan = [];
        $collisions = [];
        foreach ($pairs as $pair) {
            $relative = strtr($pair['relative'], $renames);
            $dest = $this->laravel->basePath($relative);

            if ($files->exists($dest) && ! $force) {
                $collisions[] = $relative;
            }

            $plan[] = ['absolute' => $pair['absolute'], 'relative' => $relative, 'dest' => $dest];
        }

        // Fail loud on collision — a scaffold never silently overwrites app code.
        if ($collisions !== []) {
            $this->components->error(
                'Refusing to overwrite '.count($collisions).' existing file(s): '
                .implode(', ', $collisions).'. Re-run with --force to overwrite.'
            );

            return self::FAILURE;
        }

        foreach ($plan as $item) {
            $files->ensureDirectoryExists(dirname($item['dest']));

            $contents = (string) $files->get($item['absolute']);
            $contents = $this->rewriteNamespacePlaceholders($contents, $rootNamespace);
            $contents = strtr($contents, $renames);

            $files->put($item['dest'], $contents);
        }

        $this->reportScaffold($name, $slug, $plan, $includeCommand);

        return self::SUCCESS;
    }

    /**
     * Discover every corpus tree that declares a `blueprint.json` manifest,
     * keyed by slug in stable sorted order. Trees without a manifest are
     * installable verbatim via `swarm:install:examples` but are not offered as
     * named blueprints here.
     *
     * @return array<string, array{manifest: array{slug: string, title: string, topology: string, summary: string, tokens: array{namespaceSegment: string, swarmClass: string, commandClass: string, commandSignature: string}}, dir: string}>
     */
    private function discoverBlueprints(Filesystem $files): array
    {
        $root = $this->corpusRoot();

        if (! $files->isDirectory($root)) {
            return [];
        }

        $blueprints = [];
        foreach ($files->directories($root) as $treeDir) {
            $manifest = $this->readBlueprintManifest($files, $treeDir);

            if ($manifest === null) {
                // A tree with a present-but-invalid manifest would otherwise
                // vanish from --template silently, misreporting as "unknown
                // blueprint" downstream. Surface it as an actionable warning.
                if ($files->exists($treeDir.DIRECTORY_SEPARATOR.'blueprint.json')) {
                    $this->components->warn(
                        'Ignoring malformed blueprint manifest in ['.basename($treeDir).'].'
                    );
                }

                continue;
            }

            if (isset($blueprints[$manifest['slug']])) {
                $this->components->warn(
                    "Duplicate blueprint slug [{$manifest['slug']}] — the last tree discovered wins."
                );
            }

            $blueprints[$manifest['slug']] = ['manifest' => $manifest, 'dir' => $treeDir];
        }

        ksort($blueprints);

        return $blueprints;
    }

    /**
     * Resolve which blueprint to scaffold: the `--template` slug, an interactive
     * pick, or a hard failure in non-interactive mode. Returns `null` on any
     * failure already reported to the console.
     *
     * @param  array<string, array{manifest: array{slug: string, title: string, topology: string, summary: string, tokens: array<string, string>}, dir: string}>  $blueprints
     */
    private function resolveTemplate(array $blueprints): ?string
    {
        $requested = $this->option('template');

        if (is_string($requested) && $requested !== '') {
            if (! isset($blueprints[$requested])) {
                $this->components->error(
                    "Unknown blueprint [{$requested}]. Available: ".implode(', ', array_keys($blueprints)).'.'
                );

                return null;
            }

            return $requested;
        }

        if (! $this->wantsInteraction()) {
            $this->components->error(
                'In non-interactive mode you must pass --template=<slug>. '
                .'Available: '.implode(', ', array_keys($blueprints)).'.'
            );

            return null;
        }

        $options = [];
        foreach ($blueprints as $slug => $entry) {
            $manifest = $entry['manifest'];
            $options[$slug] = $manifest['title'].' ('.$manifest['topology'].') — '.$manifest['summary'];
        }

        /** @var string $chosen */
        $chosen = select(
            label: 'Which blueprint should we scaffold?',
            options: $options,
            hint: 'Each is a complete, runnable swarm you can rename and edit.',
        );

        return $chosen;
    }

    /**
     * Resolve the swarm name from the argument or an interactive prompt, and
     * validate it studlies into a legal class name. Returns the StudlyCase name
     * or `null` on a reported failure.
     */
    private function resolveName(): ?string
    {
        $raw = $this->argument('name');
        $raw = is_string($raw) ? trim($raw) : '';

        if ($raw === '' && $this->wantsInteraction()) {
            $raw = text(
                label: 'What should we name your swarm?',
                placeholder: 'SupportTriage',
                hint: 'StudlyCase — this becomes your swarm class and namespace.',
            );
            $raw = trim($raw);
        }

        if ($raw === '') {
            $this->components->error('A swarm name is required (pass it as the first argument).');

            return null;
        }

        $name = Str::studly($raw);

        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->components->error(
                "[{$raw}] is not a valid swarm name. Use letters and numbers only (e.g. SupportTriage)."
            );

            return null;
        }

        if (in_array(strtolower($name), self::RESERVED_NAMES, true)) {
            $this->components->error(
                "[{$name}] is a reserved name and would generate a class that does not compile. Choose another name."
            );

            return null;
        }

        return $name;
    }

    /**
     * Build the token → replacement map for a blueprint's canonical names.
     * Applied via {@see strtr()} to both file contents and destination paths.
     * strtr matches the longest key at each position and never rescans a
     * replacement — independent of this array's insertion order — so the
     * substring overlap between `namespaceSegment` (e.g. `ParallelResearchFanout`)
     * and `swarmClass` (e.g. `ResearchFanout`) can never cause a partial
     * mis-replacement.
     *
     * @param  array{namespaceSegment: string, swarmClass: string, commandClass: string, commandSignature: string}  $tokens
     * @return array<string, string>
     */
    private function buildRenameMap(array $tokens, string $name): array
    {
        $kebab = Str::kebab($name);

        return [
            $tokens['commandClass'] => $name.'Command',
            $tokens['namespaceSegment'] => $name,
            $tokens['swarmClass'] => $name,
            $tokens['commandSignature'] => 'swarm:run:'.$kebab,
        ];
    }

    /**
     * Whether the command may prompt the user (interactive TTY and not
     * `--no-interaction`).
     */
    private function wantsInteraction(): bool
    {
        return $this->input->isInteractive() && $this->option('no-interaction') !== true;
    }

    /**
     * Print what was scaffolded and how to run it.
     *
     * @param  list<array{absolute: string, relative: string, dest: string}>  $plan
     */
    private function reportScaffold(string $name, string $slug, array $plan, bool $includeCommand): void
    {
        $this->components->info("Scaffolded [{$name}] from the [{$slug}] blueprint.");

        $this->components->bulletList(array_map(
            static fn (array $item): string => $item['relative'],
            $plan,
        ));

        if ($includeCommand) {
            $signature = 'swarm:run:'.Str::kebab($name);
            $this->newLine();
            $this->components->info('Run it end-to-end:');
            $this->components->bulletList(["php artisan {$signature}"]);
            $this->components->info('Artisan auto-discovery registers the new command on the next boot.');
        }

        $this->newLine();
        $this->components->info('Next: open the generated files, swap the ScriptedAgent stubs for real agents, and edit the docblocks.');
    }
}
