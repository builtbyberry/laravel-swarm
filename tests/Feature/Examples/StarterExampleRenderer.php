<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Examples;

use RuntimeException;

/**
 * Renders the starter-example stubs into a temp directory under a test-only
 * namespace and `require`s the rendered PHP so the smoke tests run the same
 * code the installer ships, not a hand-mirrored copy.
 *
 * The rewrite is a single `{{ rootNamespace }}` -> "<RootNamespace>"
 * substitution. That mirrors the contract the v0.8.0 installer harness
 * (#92) and `swarm:install:examples` (#90) consume.
 */
final class StarterExampleRenderer
{
    /**
     * Map of example slug => destination root namespace.
     *
     * @var array<string, string>
     */
    private static array $loaded = [];

    /**
     * Render the named example, returning the destination root namespace.
     */
    public static function render(string $slug): string
    {
        $rootNamespace = 'BuiltByBerry\\LaravelSwarm\\Tests\\Feature\\Examples\\Rendered\\'.self::studly($slug);

        if (isset(self::$loaded[$slug])) {
            return self::$loaded[$slug];
        }

        $source = dirname(__DIR__, 3).'/stubs/examples/'.$slug.'/app';

        if (! is_dir($source)) {
            throw new RuntimeException("Starter example source not found: {$source}");
        }

        $destination = sys_get_temp_dir().'/laravel-swarm-starter-examples/'.$slug.'/'.bin2hex(random_bytes(4));
        self::rmdir($destination);
        self::copyTree($source, $destination, $rootNamespace);

        foreach (self::collectPhp($destination) as $file) {
            require_once $file;
        }

        return self::$loaded[$slug] = $rootNamespace;
    }

    private static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    private static function copyTree(string $source, string $destination, string $rootNamespace): void
    {
        if (! is_dir($destination) && ! mkdir($destination, 0777, true) && ! is_dir($destination)) {
            throw new RuntimeException("Failed to create destination directory: {$destination}");
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $source.DIRECTORY_SEPARATOR.$entry;
            $destinationPath = $destination.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($sourcePath)) {
                self::copyTree($sourcePath, $destinationPath, $rootNamespace);

                continue;
            }

            $contents = file_get_contents($sourcePath);

            if ($contents === false) {
                throw new RuntimeException("Failed to read stub file: {$sourcePath}");
            }

            if (str_ends_with($entry, '.php')) {
                $contents = str_replace('{{ rootNamespace }}', $rootNamespace, $contents);
            }

            file_put_contents($destinationPath, $contents);
        }
    }

    /**
     * @return iterable<string>
     */
    private static function collectPhp(string $root): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield (string) $file;
            }
        }
    }

    private static function rmdir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir((string) $file);

                continue;
            }

            unlink((string) $file);
        }

        rmdir($path);
    }
}
