<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

pest()->group('process-concurrency');

function swarmUpgradeProcess(string $root, array $options = []): Process
{
    return new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/swarm-upgrade', '--path='.$root, '--json', ...$options], timeout: 10);
}

function swarmUpgradeProcessPreview(string $root): array
{
    $process = swarmUpgradeProcess($root);
    $process->run();
    expect($process->getExitCode())->toBe(1);

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $this->upgradeProcessRoot = sys_get_temp_dir().'/swarm-upgrade-process-'.bin2hex(random_bytes(8));
    mkdir($this->upgradeProcessRoot, 0700);
    $this->upgradeProcessManifest = "{\n  \"require\": {\"builtbyberry/laravel-swarm\": \"^0.25.0\"}\n}\n";
    file_put_contents($this->upgradeProcessRoot.'/composer.json', $this->upgradeProcessManifest);
    file_put_contents($this->upgradeProcessRoot.'/composer.lock', json_encode([
        'packages' => [
            ['name' => 'builtbyberry/laravel-swarm', 'version' => 'v0.25.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/builtbyberry/laravel-swarm.git', 'reference' => str_repeat('a', 40)]],
            ['name' => 'laravel/ai', 'version' => 'v0.10.3', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/ai.git', 'reference' => str_repeat('b', 40)]],
        ],
        'packages-dev' => [],
    ], JSON_THROW_ON_ERROR));
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->upgradeProcessRoot);
});

test('a real process holding the upgrade sidecar lock prevents another CLI writer', function (): void {
    $preview = swarmUpgradeProcessPreview($this->upgradeProcessRoot);
    mkdir($this->upgradeProcessRoot.'/.swarm-upgrade', 0700);
    $lockPath = $this->upgradeProcessRoot.'/.swarm-upgrade/lock';
    touch($lockPath);
    chmod($lockPath, 0600);
    $holder = new Process([PHP_BINARY, '-r', <<<'PHP'
$lock = fopen($argv[1], 'r+');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(3);
echo "LOCKED\n";
flush();
while (!file_exists($argv[2])) usleep(10000);
flock($lock, LOCK_UN);
fclose($lock);
PHP, $lockPath, $this->upgradeProcessRoot.'/release-lock'], timeout: 10);
    $holder->start();

    try {
        expect($holder->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'LOCKED')))->toBeTrue();
        $contender = swarmUpgradeProcess($this->upgradeProcessRoot, ['--apply='.$preview['actions'][0]['id'], '--expect='.$preview['preview_digest'], '--yes']);
        $contender->run();

        expect($contender->getExitCode())->toBe(2)
            ->and(json_decode($contender->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'])->toBe('error')
            ->and(file_get_contents($this->upgradeProcessRoot.'/composer.json'))->toBe($this->upgradeProcessManifest)
            ->and(glob($this->upgradeProcessRoot.'/.swarm-upgrade/*.json'))->toBe([]);
    } finally {
        file_put_contents($this->upgradeProcessRoot.'/release-lock', 'release');
        $holder->wait();
        $holder->stop();
    }

    $writer = swarmUpgradeProcess($this->upgradeProcessRoot, ['--apply='.$preview['actions'][0]['id'], '--expect='.$preview['preview_digest'], '--yes']);
    $writer->run();
    expect($holder->getExitCode())->toBe(0)
        ->and($writer->getExitCode())->toBe(0)
        ->and(json_decode($writer->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'])->toBe('applied');
});

test('two real same-digest CLI writers apply once and reject the second busy or stale writer', function (): void {
    $preview = swarmUpgradeProcessPreview($this->upgradeProcessRoot);
    $options = ['--apply='.$preview['actions'][0]['id'], '--expect='.$preview['preview_digest'], '--yes'];
    $first = swarmUpgradeProcess($this->upgradeProcessRoot, $options);
    $second = swarmUpgradeProcess($this->upgradeProcessRoot, $options);
    $first->start();
    $second->start();

    try {
        $first->wait();
        $second->wait();
        $exits = [$first->getExitCode(), $second->getExitCode()];
        sort($exits);
        $statuses = [json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'], json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status']];
        sort($statuses);

        expect($exits)->toBe([0, 2])
            ->and($statuses)->toBe(['applied', 'error'])
            ->and(json_decode(file_get_contents($this->upgradeProcessRoot.'/composer.json'), true, 512, JSON_THROW_ON_ERROR)['require']['builtbyberry/laravel-swarm'])->toBe('^0.26.1')
            ->and(glob($this->upgradeProcessRoot.'/.swarm-upgrade/*.json'))->toHaveCount(1)
            ->and(fileperms($this->upgradeProcessRoot.'/.swarm-upgrade') & 0777)->toBe(0700)
            ->and(fileperms($this->upgradeProcessRoot.'/.swarm-upgrade/lock') & 0777)->toBe(0600);

        $manifest = file_get_contents($this->upgradeProcessRoot.'/composer.json');
        $stale = swarmUpgradeProcess($this->upgradeProcessRoot, $options);
        $stale->run();
        expect($stale->getExitCode())->toBe(2)
            ->and(file_get_contents($this->upgradeProcessRoot.'/composer.json'))->toBe($manifest)
            ->and(glob($this->upgradeProcessRoot.'/.swarm-upgrade/*.json'))->toHaveCount(1);
    } finally {
        $first->stop();
        $second->stop();
    }
});
