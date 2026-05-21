<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Install;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\multiselect;

/**
 * Copy the curated starter example pack into a host Laravel app.
 *
 * Examples live under the package's `stubs/examples/` directory as full
 * `app/Ai/Swarms/<Name>/`, `app/Ai/Agents/<Name>/`, and
 * `app/Console/Commands/SwarmExample<Name>Command.php` trees, with
 * `{{ rootNamespace }}` placeholders the installer rewrites to the host app's
 * PSR-4 root (usually `App\`). The per-example `README.md` files are
 * deliberately not copied — they exist as reference material in the package's
 * own tree, not as noise inside the user's `app/` directory.
 *
 * Auto-discovery in Laravel 11+ scans `app/Console/Commands/` on every boot,
 * so the copied runner commands register automatically — the installer does
 * not touch `routes/console.php`.
 */
#[AsCommand(name: 'swarm:install:examples')]
final class InstallExamplesCommand extends Command
{
    /** @var string */
    protected $signature = 'swarm:install:examples
        {--example=* : Install one specific example by directory name (repeatable)}
        {--all : Install every available starter example without prompting}
        {--force : Overwrite existing example files in the host app}';

    /** @var string */
    protected $description = 'Copy curated starter example swarms into the host app.';

    public function handle(Filesystem $files): int
    {
        $sourceRoot = $this->stubsRoot();

        if (! $files->isDirectory($sourceRoot)) {
            $this->components->error("Cannot locate starter examples at [{$sourceRoot}].");

            return self::FAILURE;
        }

        $available = $this->discoverExamples($files, $sourceRoot);

        if ($available === []) {
            $this->components->error('No starter examples are available in the package.');

            return self::FAILURE;
        }

        $selection = $this->resolveSelection($available);

        if ($selection === null) {
            return self::FAILURE;
        }

        if ($selection === []) {
            $this->components->warn('No examples selected. Nothing to install.');

            return self::SUCCESS;
        }

        $rootNamespace = $this->resolveRootNamespace($files);

        $force = (bool) $this->option('force');
        $installed = [];
        $skipped = [];

        foreach ($selection as $name) {
            $result = $this->installExample(
                files: $files,
                exampleName: $name,
                exampleSourceRoot: $sourceRoot.DIRECTORY_SEPARATOR.$name,
                rootNamespace: $rootNamespace,
                force: $force,
            );

            if ($result === 'installed') {
                $installed[] = $name;
            } elseif ($result === 'skipped') {
                $skipped[] = $name;
            } elseif ($result === 'refused') {
                return self::FAILURE;
            }
        }

        $this->printPostInstallHints($installed, $skipped, $available);

        return self::SUCCESS;
    }

    /**
     * Absolute path to the package's bundled starter examples.
     */
    private function stubsRoot(): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'examples';
    }

    /**
     * Discover the curated examples shipped under `stubs/examples/`.
     *
     * @return array<string, array{name: string, description: string, runner: ?string}>
     *                                                                                  keyed by directory name, in stable sorted order.
     */
    private function discoverExamples(Filesystem $files, string $sourceRoot): array
    {
        $entries = [];

        foreach (new FilesystemIterator($sourceRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var \SplFileInfo $entry */
            if (! $entry->isDir()) {
                continue;
            }

            $name = $entry->getFilename();
            $entries[$name] = [
                'name' => $name,
                'description' => $this->readDescription($files, $entry->getPathname()),
                'runner' => $this->detectRunnerCommandName($files, $entry->getPathname()),
            ];
        }

        ksort($entries);

        return $entries;
    }

    /**
     * Read the first non-title paragraph line from an example's README as a
     * short one-line description. Falls back to a generic label if the README
     * is missing or only contains the title.
     */
    private function readDescription(Filesystem $files, string $exampleDir): string
    {
        $readme = $exampleDir.DIRECTORY_SEPARATOR.'README.md';

        if (! $files->exists($readme)) {
            return 'Starter example.';
        }

        $contents = (string) $files->get($readme);

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Strip markdown emphasis and link wrappers for a clean one-liner.
            $line = (string) preg_replace('/[*_`]/', '', $line);

            return $line;
        }

        return 'Starter example.';
    }

    /**
     * Inspect the example's `app/Console/Commands/` directory and return the
     * first artisan command name declared via the `#[AsCommand(name: ...)]`
     * attribute. Used for post-install "you can now run" hints.
     */
    private function detectRunnerCommandName(Filesystem $files, string $exampleDir): ?string
    {
        $commandsDir = $exampleDir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR
            .'Console'.DIRECTORY_SEPARATOR.'Commands';

        if (! $files->isDirectory($commandsDir)) {
            return null;
        }

        foreach (new FilesystemIterator($commandsDir, FilesystemIterator::SKIP_DOTS) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) $files->get($file->getPathname());

            if (preg_match('/#\[AsCommand\s*\(\s*name:\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Resolve which examples to install based on flags + interactive prompt.
     *
     * Returns `null` on a hard validation failure (already reported to the
     * console), an empty array if the user picked nothing, or a non-empty
     * list of example directory names otherwise.
     *
     * @param  array<string, array{name: string, description: string, runner: ?string}>  $available
     * @return list<string>|null
     */
    private function resolveSelection(array $available): ?array
    {
        $requested = array_values(array_filter(
            (array) $this->option('example'),
            static fn ($value): bool => is_string($value) && $value !== '',
        ));

        $all = (bool) $this->option('all');

        if ($all === true && $requested !== []) {
            $this->components->error('Pass either --all or --example=<name>, not both.');

            return null;
        }

        if ($all === true) {
            return array_keys($available);
        }

        if ($requested !== []) {
            $unknown = array_values(array_diff($requested, array_keys($available)));

            if ($unknown !== []) {
                $this->components->error(
                    'Unknown example(s): '.implode(', ', $unknown).'. '
                    .'Available: '.implode(', ', array_keys($available)).'.'
                );

                return null;
            }

            return array_values(array_unique($requested));
        }

        if (! $this->input->isInteractive() || $this->option('no-interaction') === true) {
            $this->components->error(
                'In non-interactive mode you must pass --all or one or more --example=<name> flags. '
                .'Available: '.implode(', ', array_keys($available)).'.'
            );

            return null;
        }

        $choices = [];
        foreach ($available as $name => $meta) {
            $choices[$name] = $name.' — '.$meta['description'];
        }

        /** @var array<int, string> $chosen */
        $chosen = multiselect(
            label: 'Which starter examples should be installed?',
            options: $choices,
            hint: 'Space to toggle, enter to confirm.',
        );

        return array_values($chosen);
    }

    /**
     * Read the host app's PSR-4 root namespace from `composer.json`. Falls
     * back to `App` if the file is missing, malformed, or uses a layout the
     * installer cannot interpret unambiguously (the same default Laravel
     * itself uses).
     */
    private function resolveRootNamespace(Filesystem $files): string
    {
        $composerPath = $this->laravel->basePath('composer.json');

        if (! $files->exists($composerPath)) {
            return 'App';
        }

        try {
            /** @var array<string, mixed> $composer */
            $composer = json_decode((string) $files->get($composerPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'App';
        }

        $psr4 = $composer['autoload']['psr-4'] ?? null;

        if (! is_array($psr4) || $psr4 === []) {
            return 'App';
        }

        // Prefer a mapping pointed at app/ (the Laravel convention). If none
        // match, fall back to the first declared mapping, then to App.
        foreach ($psr4 as $namespace => $path) {
            if (is_string($path) && rtrim($path, '/\\') === 'app') {
                return rtrim((string) $namespace, '\\');
            }
        }

        $first = array_key_first($psr4);

        return is_string($first) ? rtrim($first, '\\') : 'App';
    }

    /**
     * Copy one example tree into the host app, rewriting namespace
     * placeholders along the way. Returns:
     *
     *   - 'installed' on a fresh copy or a --force overwrite
     *   - 'skipped' when at least one file already exists and --force is
     *     absent (existing files are left untouched; this is the idempotent
     *     no-op path)
     *   - 'refused' when --force was required but missing AND we want to
     *     halt the whole batch (we never do today; existing-file collisions
     *     fall under 'skipped' instead so multi-example invocations stay
     *     additive). Reserved for future hard refusals.
     *
     * @return 'installed'|'skipped'|'refused'
     */
    private function installExample(
        Filesystem $files,
        string $exampleName,
        string $exampleSourceRoot,
        string $rootNamespace,
        bool $force,
    ): string {
        $pairs = $this->collectCopyPairs($exampleSourceRoot);

        // The README is reference material that belongs in the package, not
        // in the user's app/ tree. Skip it explicitly.
        $pairs = array_values(array_filter(
            $pairs,
            static fn (array $pair): bool => $pair['relative'] !== 'README.md',
        ));

        $existing = [];
        foreach ($pairs as $pair) {
            $dest = $this->laravel->basePath($pair['relative']);
            if ($files->exists($dest)) {
                $existing[] = $pair['relative'];
            }
        }

        if ($existing !== [] && $force === false) {
            $this->components->warn(
                "Skipping [{$exampleName}]: ".count($existing).' file(s) already exist. '
                .'Re-run with --force to overwrite.'
            );

            return 'skipped';
        }

        foreach ($pairs as $pair) {
            $source = $pair['absolute'];
            $dest = $this->laravel->basePath($pair['relative']);

            $files->ensureDirectoryExists(dirname($dest));

            $contents = (string) $files->get($source);
            $contents = $this->rewriteNamespacePlaceholders($contents, $rootNamespace);

            $files->put($dest, $contents);
        }

        $this->components->info("Installed example [{$exampleName}].");

        return 'installed';
    }

    /**
     * Enumerate every file under the example source directory, returning the
     * absolute source path and the destination path *relative to the host
     * app's base directory* (i.e. preserving `app/Ai/Swarms/...` shape).
     *
     * @return list<array{absolute: string, relative: string}>
     */
    private function collectCopyPairs(string $exampleSourceRoot): array
    {
        $pairs = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $exampleSourceRoot,
                FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $prefixLength = strlen($exampleSourceRoot) + 1;

        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            if (! $info->isFile()) {
                continue;
            }

            $absolute = $info->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, $prefixLength));

            $pairs[] = [
                'absolute' => $absolute,
                'relative' => $relative,
            ];
        }

        return $pairs;
    }

    /**
     * Rewrite the package's two supported namespace placeholders to the
     * host app's PSR-4 root.
     *
     * Both `{{ rootNamespace }}` (canonical) and `{{ namespace }}` (legacy
     * alias for compatibility with the AC wording in #90) are accepted, with
     * arbitrary whitespace inside the braces, so hand-edited or future stubs
     * stay compatible.
     */
    private function rewriteNamespacePlaceholders(string $contents, string $rootNamespace): string
    {
        $replacement = $rootNamespace;

        $contents = (string) preg_replace(
            '/\{\{\s*rootNamespace\s*\}\}/',
            $replacement,
            $contents,
        );

        $contents = (string) preg_replace(
            '/\{\{\s*namespace\s*\}\}/',
            $replacement,
            $contents,
        );

        return $contents;
    }

    /**
     * Print "you can now run" hints for every freshly-installed example, and
     * an inert summary for any that were skipped.
     *
     * @param  list<string>  $installed
     * @param  list<string>  $skipped
     * @param  array<string, array{name: string, description: string, runner: ?string}>  $available
     */
    private function printPostInstallHints(array $installed, array $skipped, array $available): void
    {
        if ($installed === [] && $skipped === []) {
            return;
        }

        $this->newLine();

        if ($installed !== []) {
            $this->components->info('Installed '.count($installed).' starter example(s).');

            foreach ($installed as $name) {
                $runner = $available[$name]['runner'] ?? null;
                if ($runner !== null) {
                    $this->components->bulletList([
                        "php artisan {$runner}",
                    ]);
                }
            }

            $this->components->info(
                'Artisan auto-discovery picks up the new runner commands on the next boot.'
            );
        }

        if ($skipped !== []) {
            $this->components->warn(
                'Skipped (already installed): '.implode(', ', $skipped).'. '
                .'Re-run with --force to overwrite.'
            );
        }
    }
}
