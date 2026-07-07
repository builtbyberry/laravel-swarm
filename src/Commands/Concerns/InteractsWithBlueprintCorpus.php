<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Concerns;

use FilesystemIterator;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Shared machinery for the two commands that consume the bundled corpus of
 * curated swarm trees under `stubs/examples/`:
 *
 *  - `swarm:install:examples` lands a tree **verbatim** (fixed-name reference
 *    material to read), rewriting only the `{{ rootNamespace }}` placeholder.
 *  - `make:swarm:blueprint` lands the same tree **renamed** (a starting point
 *    to edit), additionally rewriting the tree's canonical class/command names
 *    to the developer's chosen name via the per-tree `blueprint.json` manifest.
 *
 * Keeping the low-level file-walking, namespace resolution, and placeholder
 * rewriting in one place guarantees the two commands stay in lockstep — in
 * particular that a blueprint scaffolds the exact same bytes `install:examples`
 * lands, modulo the rename map.
 *
 * Files that are package-side reference material and must never reach the host
 * app tree — the per-tree `README.md` and `blueprint.json` — are enumerated in
 * {@see self::CORPUS_META_FILES} and skipped by both commands.
 *
 * Every method here is container-independent (no `$this->laravel`), so the
 * concern is unit-testable in isolation. Resolving the host root namespace —
 * the one step that needs the application instance — lives in the companion
 * {@see ResolvesHostRootNamespace} trait.
 */
trait InteractsWithBlueprintCorpus
{
    /**
     * Tree-root files that document/describe a corpus entry for the package's
     * own tooling and must not be copied into the host app.
     *
     * @var list<string>
     */
    private const CORPUS_META_FILES = ['README.md', 'blueprint.json'];

    /**
     * Absolute path to the package's bundled corpus of curated swarm trees.
     */
    protected function corpusRoot(): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'examples';
    }

    /**
     * Read a tree's `blueprint.json` manifest, or `null` when the tree does not
     * declare one (it is then installable verbatim but not scaffoldable as a
     * named blueprint).
     *
     * @return array{slug: string, title: string, topology: string, summary: string, tokens: array{namespaceSegment: string, swarmClass: string, commandClass: string, commandSignature: string}}|null
     */
    protected function readBlueprintManifest(Filesystem $files, string $treeDir): ?array
    {
        $manifestPath = $treeDir.DIRECTORY_SEPARATOR.'blueprint.json';

        if (! $files->exists($manifestPath)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $tokens = $decoded['tokens'] ?? null;

        if (! is_array($tokens)) {
            return null;
        }

        foreach (['namespaceSegment', 'swarmClass', 'commandClass', 'commandSignature'] as $required) {
            if (! isset($tokens[$required]) || ! is_string($tokens[$required]) || $tokens[$required] === '') {
                return null;
            }
        }

        foreach (['slug', 'title', 'topology'] as $required) {
            if (! isset($decoded[$required]) || ! is_string($decoded[$required]) || $decoded[$required] === '') {
                return null;
            }
        }

        return [
            'slug' => $decoded['slug'],
            'title' => $decoded['title'],
            'topology' => $decoded['topology'],
            'summary' => is_string($decoded['summary'] ?? null) ? $decoded['summary'] : $decoded['title'],
            'tokens' => [
                'namespaceSegment' => $tokens['namespaceSegment'],
                'swarmClass' => $tokens['swarmClass'],
                'commandClass' => $tokens['commandClass'],
                'commandSignature' => $tokens['commandSignature'],
            ],
        ];
    }

    /**
     * Enumerate every file under a tree directory, returning the absolute source
     * path and the destination path *relative to the host app's base directory*
     * (i.e. preserving `app/Ai/Swarms/...` shape). The package-side meta files
     * ({@see self::CORPUS_META_FILES}) are excluded.
     *
     * @return list<array{absolute: string, relative: string}>
     */
    protected function collectCopyPairs(string $treeSourceRoot): array
    {
        $pairs = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $treeSourceRoot,
                FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $prefixLength = strlen($treeSourceRoot) + 1;

        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            if (! $info->isFile()) {
                continue;
            }

            $absolute = $info->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, $prefixLength));

            if (in_array($relative, self::CORPUS_META_FILES, true)) {
                continue;
            }

            $pairs[] = [
                'absolute' => $absolute,
                'relative' => $relative,
            ];
        }

        return $pairs;
    }

    /**
     * Rewrite the package's two supported namespace placeholders to the host
     * app's PSR-4 root.
     *
     * Both `{{ rootNamespace }}` (canonical) and `{{ namespace }}` (legacy alias
     * for compatibility with the AC wording in #90) are accepted, with arbitrary
     * whitespace inside the braces, so hand-edited or future stubs stay
     * compatible.
     */
    protected function rewriteNamespacePlaceholders(string $contents, string $rootNamespace): string
    {
        $contents = (string) preg_replace(
            '/\{\{\s*rootNamespace\s*\}\}/',
            $rootNamespace,
            $contents,
        );

        $contents = (string) preg_replace(
            '/\{\{\s*namespace\s*\}\}/',
            $rootNamespace,
            $contents,
        );

        return $contents;
    }
}
