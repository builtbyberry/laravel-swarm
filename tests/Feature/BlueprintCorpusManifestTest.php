<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\Concerns\InteractsWithBlueprintCorpus;
use Illuminate\Filesystem\Filesystem;

/**
 * Exercises the manifest-parsing seam of the shared corpus concern directly, so
 * the malformed-manifest paths are covered without planting a broken fixture in
 * the real `stubs/examples/` corpus (which install:examples / the renderer walk).
 */
function manifestProbe(): object
{
    return new class
    {
        use InteractsWithBlueprintCorpus;

        /** @return array<string, mixed>|null */
        public function read(Filesystem $files, string $dir): ?array
        {
            return $this->readBlueprintManifest($files, $dir);
        }
    };
}

function writeManifest(string $dir, string $contents): void
{
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir.'/blueprint.json', $contents);
}

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/laravel-swarm-manifest-test/'.bin2hex(random_bytes(4));
    mkdir($this->tmp, 0777, true);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->tmp);
});

test('a well-formed manifest parses into the expected shape', function () {
    writeManifest($this->tmp, json_encode([
        'slug' => 'demo',
        'title' => 'Demo',
        'topology' => 'sequential',
        'summary' => 'A demo.',
        'tokens' => [
            'namespaceSegment' => 'DemoTree',
            'swarmClass' => 'DemoSwarm',
            'commandClass' => 'SwarmExampleDemoCommand',
            'commandSignature' => 'swarm:example:demo',
        ],
    ], JSON_THROW_ON_ERROR));

    $manifest = manifestProbe()->read(new Filesystem, $this->tmp);

    expect($manifest)->not->toBeNull()
        ->and($manifest['slug'])->toBe('demo')
        ->and($manifest['tokens']['swarmClass'])->toBe('DemoSwarm');
});

test('a summary defaults to the title when omitted', function () {
    writeManifest($this->tmp, json_encode([
        'slug' => 'demo',
        'title' => 'Demo Title',
        'topology' => 'sequential',
        'tokens' => [
            'namespaceSegment' => 'DemoTree',
            'swarmClass' => 'DemoSwarm',
            'commandClass' => 'SwarmExampleDemoCommand',
            'commandSignature' => 'swarm:example:demo',
        ],
    ], JSON_THROW_ON_ERROR));

    expect(manifestProbe()->read(new Filesystem, $this->tmp)['summary'])->toBe('Demo Title');
});

test('an absent manifest returns null', function () {
    expect(manifestProbe()->read(new Filesystem, $this->tmp))->toBeNull();
});

test('malformed JSON returns null', function () {
    writeManifest($this->tmp, '{ this is not json ]');

    expect(manifestProbe()->read(new Filesystem, $this->tmp))->toBeNull();
});

test('a manifest missing a required token returns null', function () {
    writeManifest($this->tmp, json_encode([
        'slug' => 'demo',
        'title' => 'Demo',
        'topology' => 'sequential',
        'tokens' => [
            'namespaceSegment' => 'DemoTree',
            // swarmClass omitted
            'commandClass' => 'SwarmExampleDemoCommand',
            'commandSignature' => 'swarm:example:demo',
        ],
    ], JSON_THROW_ON_ERROR));

    expect(manifestProbe()->read(new Filesystem, $this->tmp))->toBeNull();
});

test('a manifest missing a required top-level field returns null', function () {
    writeManifest($this->tmp, json_encode([
        // slug omitted
        'title' => 'Demo',
        'topology' => 'sequential',
        'tokens' => [
            'namespaceSegment' => 'DemoTree',
            'swarmClass' => 'DemoSwarm',
            'commandClass' => 'SwarmExampleDemoCommand',
            'commandSignature' => 'swarm:example:demo',
        ],
    ], JSON_THROW_ON_ERROR));

    expect(manifestProbe()->read(new Filesystem, $this->tmp))->toBeNull();
});
