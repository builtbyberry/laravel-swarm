<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Concerns;

use Illuminate\Filesystem\Filesystem;

/**
 * Resolve the host application's PSR-4 root namespace from its `composer.json`.
 *
 * Kept separate from {@see InteractsWithBlueprintCorpus} because this one step
 * needs the command's `$this->laravel` application instance to locate the host
 * app root — everything else in the corpus concern is container-independent and
 * unit-testable on its own. Intended for use on an `Illuminate\Console\Command`.
 */
trait ResolvesHostRootNamespace
{
    /**
     * Read the host app's PSR-4 root namespace from `composer.json`. Falls back
     * to `App` if the file is missing, malformed, or uses a layout that cannot
     * be interpreted unambiguously (the same default Laravel itself uses).
     */
    protected function resolveRootNamespace(Filesystem $files): string
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
}
