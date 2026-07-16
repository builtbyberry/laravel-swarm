<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Concerns;

use Illuminate\Container\Container;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Filesystem\CopyFile;
use Laravel\Ai\Tools\Filesystem\DeleteFile;
use Laravel\Ai\Tools\Filesystem\FileExists;
use Laravel\Ai\Tools\Filesystem\FilesystemTool;
use Laravel\Ai\Tools\Filesystem\GetFileMetadata;
use Laravel\Ai\Tools\Filesystem\GetFileUrl;
use Laravel\Ai\Tools\Filesystem\ListFiles;
use Laravel\Ai\Tools\Filesystem\ReadFile;
use Laravel\Ai\Tools\Filesystem\WriteFile;

/**
 * Opt-in trait that adds Laravel AI's filesystem tools to a `laravel/ai` agent,
 * every operation scoped to a single configured Filesystem disk.
 *
 * Merge {@see swarmFilesystemTools()} into the agent's own `tools()`:
 *
 * ```php
 * use Laravel\Ai\Contracts\HasTools;
 * use BuiltByBerry\LaravelSwarm\Concerns\HasSwarmFilesystemTools;
 *
 * class Archivist implements Agent, HasTools
 * {
 *     use HasSwarmFilesystemTools;
 *
 *     public function tools(): iterable
 *     {
 *         return [...$this->swarmFilesystemTools(), new MyOtherTool];
 *     }
 * }
 * ```
 *
 * Whether tools are returned is governed by `config('swarm.filesystem.tools')`.
 * Two switches must both be set, so adding the trait is safe and inert until you
 * opt in deliberately:
 *
 *  - `enabled` is the master switch (default off).
 *  - `disk` names the Filesystem disk every tool is bound to. It has **no
 *    default** — while it is null the trait returns no tools even when `enabled`
 *    is true. Point it at a dedicated, sandboxed disk (never `local`/`public`):
 *    whatever that disk can reach is the blast radius of the write/delete tools.
 *
 * Each tool has its own toggle (default on once `enabled` is true), so a
 * read-only agent can leave `write_file` / `delete_file` / `copy_file` off. The
 * tools take only disk-relative paths and cannot traverse outside the disk root.
 */
trait HasSwarmFilesystemTools
{
    /**
     * The Laravel AI filesystem tools enabled for this agent, per
     * `swarm.filesystem.tools`, each bound to the configured disk.
     *
     * @return array<int, Tool>
     */
    public function swarmFilesystemTools(): array
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return [];
        }

        $config = $container->make('config');

        if (! (bool) $config->get('swarm.filesystem.tools.enabled', false)) {
            return [];
        }

        $disk = $config->get('swarm.filesystem.tools.disk');

        // No disk, no tools: the tools stay inert until a sandboxed disk is named,
        // so `enabled` alone can never expose a filesystem the operator did not
        // deliberately scope for agent use.
        if (! is_string($disk) || $disk === '') {
            return [];
        }

        $tools = [];

        foreach (self::swarmFilesystemToolMap() as $key => $class) {
            if ((bool) $config->get("swarm.filesystem.tools.{$key}", true)) {
                $tools[] = new $class($disk);
            }
        }

        return $tools;
    }

    /**
     * Map each config toggle to the Laravel AI filesystem tool it enables.
     * Every tool extends {@see FilesystemTool}, whose constructor takes the disk.
     *
     * @return array<string, class-string<FilesystemTool>>
     */
    protected static function swarmFilesystemToolMap(): array
    {
        return [
            'read_file' => ReadFile::class,
            'write_file' => WriteFile::class,
            'list_files' => ListFiles::class,
            'delete_file' => DeleteFile::class,
            'copy_file' => CopyFile::class,
            'file_exists' => FileExists::class,
            'get_file_metadata' => GetFileMetadata::class,
            'get_file_url' => GetFileUrl::class,
        ];
    }
}
