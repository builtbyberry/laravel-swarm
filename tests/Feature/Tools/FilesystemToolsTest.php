<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FilesystemToolAgent;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Filesystem\CopyFile;
use Laravel\Ai\Tools\Filesystem\DeleteFile;
use Laravel\Ai\Tools\Filesystem\FileExists;
use Laravel\Ai\Tools\Filesystem\GetFileMetadata;
use Laravel\Ai\Tools\Filesystem\GetFileUrl;
use Laravel\Ai\Tools\Filesystem\ListFiles;
use Laravel\Ai\Tools\Filesystem\ReadFile;
use Laravel\Ai\Tools\Filesystem\WriteFile;
use Laravel\Ai\Tools\Request;
use League\Flysystem\PathTraversalDetected;

/**
 * @return array<int, Tool>
 */
function filesystemTools(): array
{
    return (new FilesystemToolAgent)->swarmFilesystemTools();
}

/**
 * @return array<int, class-string>
 */
function filesystemToolClasses(): array
{
    return array_map('get_class', filesystemTools());
}

test('exposes no tools when disabled, even with a disk set (default posture)', function () {
    config()->set('swarm.filesystem.tools.enabled', false);
    config()->set('swarm.filesystem.tools.disk', 'sandbox');

    expect(filesystemTools())->toBe([]);
});

test('exposes no tools when enabled but the disk is null, empty, or non-string', function (mixed $disk) {
    config()->set('swarm.filesystem.tools.enabled', true);
    config()->set('swarm.filesystem.tools.disk', $disk);

    expect(filesystemTools())->toBe([]);
})->with([
    'null' => [null],
    'empty string' => [''],
    'non-string' => [123],
]);

test('exposes all eight tools when enabled with a disk', function () {
    config()->set('swarm.filesystem.tools.enabled', true);
    config()->set('swarm.filesystem.tools.disk', 'sandbox');

    // Assert the exact class set — a map typo swapping one tool for a duplicate
    // would keep the count at 8 but fail this.
    expect(filesystemToolClasses())->toEqualCanonicalizing([
        ReadFile::class,
        WriteFile::class,
        ListFiles::class,
        DeleteFile::class,
        CopyFile::class,
        FileExists::class,
        GetFileMetadata::class,
        GetFileUrl::class,
    ]);
});

test('per-tool toggles drop the disabled tools', function () {
    config()->set('swarm.filesystem.tools.enabled', true);
    config()->set('swarm.filesystem.tools.disk', 'sandbox');
    config()->set('swarm.filesystem.tools.write_file', false);
    config()->set('swarm.filesystem.tools.delete_file', false);
    config()->set('swarm.filesystem.tools.copy_file', false);

    $classes = filesystemToolClasses();

    // The read-only subset remains; the mutating tools are gone.
    expect(filesystemTools())->toHaveCount(5);
    expect($classes)->toContain(ReadFile::class, ListFiles::class);
    expect($classes)->not->toContain(WriteFile::class, DeleteFile::class, CopyFile::class);
});

test('tools are bound to the configured disk and confined to its root', function () {
    Storage::fake('sandbox');
    Storage::fake('other');

    config()->set('swarm.filesystem.tools.enabled', true);
    config()->set('swarm.filesystem.tools.disk', 'sandbox');

    $tools = collect(filesystemTools())->keyBy(fn (object $tool): string => $tool::class);
    $write = $tools[WriteFile::class];
    $read = $tools[ReadFile::class];

    // A write lands on the configured disk only.
    $write->handle(new Request(['path' => 'notes/todo.txt', 'contents' => 'hello sandbox']));

    Storage::disk('sandbox')->assertExists('notes/todo.txt');
    expect(Storage::disk('sandbox')->get('notes/todo.txt'))->toBe('hello sandbox');
    // A different disk is never touched — the binding is exclusive.
    Storage::disk('other')->assertMissing('notes/todo.txt');

    // And a read reads back through the same disk.
    expect((string) $read->handle(new Request(['path' => 'notes/todo.txt'])))->toBe('hello sandbox');
});

test('a model-supplied traversal path cannot escape the disk root', function () {
    Storage::fake('sandbox');

    config()->set('swarm.filesystem.tools.enabled', true);
    config()->set('swarm.filesystem.tools.disk', 'sandbox');

    $tools = collect(filesystemTools())->keyBy(fn (object $tool): string => $tool::class);

    // Reads: `..` traversal is rejected at the disk boundary — the tool reports
    // the path as missing rather than reading anything outside the sandbox root.
    $result = (string) $tools[ReadFile::class]->handle(new Request(['path' => '../../../../../../etc/passwd']));

    expect($result)
        ->toContain('does not exist')
        ->not->toContain('root:');

    // Writes are the higher-consequence vector: a traversal escape must not
    // create a file anywhere. The disk boundary rejects it outright (Flysystem
    // raises PathTraversalDetected — a harder stop than ReadFile's graceful
    // "does not exist"), so nothing is written and the sandbox stays empty.
    expect(fn () => $tools[WriteFile::class]->handle(new Request([
        'path' => '../escaped.txt',
        'contents' => 'should never be written outside the sandbox',
    ])))->toThrow(PathTraversalDetected::class);

    expect(Storage::disk('sandbox')->allFiles())->toBe([]);
});
