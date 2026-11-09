<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\LockBarrier;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\MarkerPublicationWorker;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Filesystem\Filesystem;

pest()->group('process-concurrency');

beforeEach(function (): void {
    $this->markerDirectory = sys_get_temp_dir().'/swarm-marker-publication-'.bin2hex(random_bytes(8));
    mkdir($this->markerDirectory);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->markerDirectory);
});

test('a marker becomes visible only after its complete value is ready', function (): void {
    $observed = false;
    $dir = $this->markerDirectory;
    LockBarrier::signal($dir, 'pid', '316', function (string $temporary) use ($dir, &$observed): void {
        $observed = true;
        expect(dirname($temporary))->toBe($dir)
            ->and(file_get_contents($temporary))->toBe('316')
            ->and(is_file($dir.'/pid'))->toBeFalse();
        expect(fn () => LockBarrier::await($dir, 'pid', 0.01))->toThrow(RuntimeException::class, 'Timed out waiting for barrier');
    });

    expect($observed)->toBeTrue()
        ->and(LockBarrier::await($dir, 'pid'))->toBe('316')
        ->and(scandir($dir))->toBe(['.', '..', 'pid']);
});

test('a replacement marker keeps the previous complete value until publication', function (): void {
    $dir = $this->markerDirectory;
    LockBarrier::signal($dir, 'pid', '315');
    $observed = false;
    LockBarrier::signal($dir, 'pid', '316', function (string $temporary) use ($dir, &$observed): void {
        $observed = true;
        expect(file_get_contents($temporary))->toBe('316')
            ->and(LockBarrier::await($dir, 'pid'))->toBe('315');
    });

    expect($observed)->toBeTrue()
        ->and(LockBarrier::await($dir, 'pid'))->toBe('316')
        ->and(scandir($dir))->toBe(['.', '..', 'pid']);
});

test('an interrupted publication cleans its temporary file and preserves the old marker', function (): void {
    $dir = $this->markerDirectory;
    LockBarrier::signal($dir, 'pid', '315');
    $failure = new RuntimeException('publication interrupted');
    $caught = null;
    try {
        LockBarrier::signal($dir, 'pid', '316', function (string $temporary) use ($failure): void {
            expect(file_get_contents($temporary))->toBe('316');
            throw $failure;
        });
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->toBe($failure)
        ->and(LockBarrier::await($dir, 'pid'))->toBe('315')
        ->and(scandir($dir))->toBe(['.', '..', 'pid']);
});

test('a failed marker rename throws and removes its unpublished file', function (): void {
    $dir = $this->markerDirectory;
    mkdir($dir.'/pid');
    expect(fn () => LockBarrier::signal($dir, 'pid', '316'))
        ->toThrow(RuntimeException::class, 'Could not publish barrier');
    expect(is_dir($dir.'/pid'))->toBeTrue()
        ->and(scandir($dir))->toBe(['.', '..', 'pid']);
});

test('a missing marker directory fails clearly without a temporary fallback', function (): void {
    $dir = $this->markerDirectory.'/missing';
    expect(fn () => LockBarrier::signal($dir, 'pid', '316'))
        ->toThrow(RuntimeException::class, 'Could not create barrier');
    expect(scandir($this->markerDirectory))->toBe(['.', '..']);
});

test('marker publication preserves empty and zero values', function (): void {
    foreach (['', '0'] as $value) {
        LockBarrier::signal($this->markerDirectory, 'value', $value);
        expect(LockBarrier::await($this->markerDirectory, 'value'))->toBe($value);
    }
});

test('two real processes exchange complete numeric markers', function (): void {
    $count = 100;
    [$published, $mismatches] = app(ConcurrencyManager::class)->driver('process')->run([
        MarkerPublicationWorker::writer($this->markerDirectory, $count),
        MarkerPublicationWorker::reader($this->markerDirectory, $count),
    ]);

    expect($published)->toBe($count)
        ->and($mismatches)->toBe([])
        ->and(scandir($this->markerDirectory))->toBe(['.', '..']);
});
