<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Upgrade\UpgradeConsole;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

function swarmUpgradeCli(string $path, array $options = []): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/swarm-upgrade', '--path='.$path, ...$options]);
    $process->setTimeout(10);
    $process->run();

    return $process;
}

function swarmUpgradeCliReport(Process $process): array
{
    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $this->upgradeCommandRoot = sys_get_temp_dir().'/swarm-upgrade-command-'.bin2hex(random_bytes(8));
    mkdir($this->upgradeCommandRoot, 0700);
    $this->upgradeCommandManifest = json_encode([
        'name' => 'example/old-application',
        'require' => ['php' => '^8.4', 'builtbyberry/laravel-swarm' => '^0.25.0', 'laravel/ai' => '^0.10.3'],
        'require-dev' => ['builtbyberry/laravel-swarm-filament' => '0.2.2'],
        'scripts' => ['post-update-cmd' => 'do-not-execute-this-script'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    file_put_contents($this->upgradeCommandRoot.'/composer.json', $this->upgradeCommandManifest);
    file_put_contents($this->upgradeCommandRoot.'/composer.lock', json_encode([
        'packages' => [
            ['name' => 'builtbyberry/laravel-swarm', 'version' => 'v0.25.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/builtbyberry/laravel-swarm.git', 'reference' => str_repeat('a', 40)]],
            ['name' => 'laravel/ai', 'version' => 'v0.10.3', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/ai.git', 'reference' => str_repeat('b', 40)]],
        ],
        'packages-dev' => [
            ['name' => 'builtbyberry/laravel-swarm-filament', 'version' => 'v0.2.2', 'source' => ['type' => 'git', 'url' => 'https://github.com/builtbyberry/laravel-swarm-filament.git', 'reference' => str_repeat('c', 40)]],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->upgradeCommandRoot);
});

test('standalone preview inspects an old application without executing its bootstrap or installed PHP', function (): void {
    mkdir($this->upgradeCommandRoot.'/bootstrap');
    mkdir($this->upgradeCommandRoot.'/vendor/composer', 0700, true);
    $marker = $this->upgradeCommandRoot.'/target-code-executed';
    $bomb = '<?php file_put_contents('.var_export($marker, true).', "executed"); throw new RuntimeException("Target application must not boot");';
    foreach (['bootstrap/app.php', 'vendor/autoload.php', 'vendor/composer/installed.php'] as $file) {
        file_put_contents($this->upgradeCommandRoot.'/'.$file, $bomb);
    }
    $before = (new Filesystem)->allFiles($this->upgradeCommandRoot, true);
    $process = swarmUpgradeCli($this->upgradeCommandRoot, ['--json']);
    $report = swarmUpgradeCliReport($process);

    expect($process->getExitCode())->toBe(1)
        ->and($report)->toMatchArray(['schema_version' => 1, 'recipe' => '0.25-to-0.26', 'target' => '0.26.1', 'status' => 'preview', 'runtime_verified' => false, 'backup_id' => null])
        ->and($report['preview_digest'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($report['inventory']['builtbyberry/laravel-swarm'])->toHaveKeys(['constraint', 'locked', 'installed'])
        ->and($report['inventory']['builtbyberry/laravel-swarm']['constraint'])->toBe('^0.25.0')
        ->and($report['can_apply'])->toBeTrue()
        ->and($report['findings'])->not->toBeEmpty()
        ->and(file_exists($marker))->toBeFalse()
        ->and(file_exists($this->upgradeCommandRoot.'/.swarm-upgrade'))->toBeFalse()
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.json'))->toBe($this->upgradeCommandManifest)
        ->and(count((new Filesystem)->allFiles($this->upgradeCommandRoot, true)))->toBe(count($before));
});

test('standalone preview also works when the target has no vendor directory', function (): void {
    $process = swarmUpgradeCli($this->upgradeCommandRoot, ['--json']);

    expect($process->getExitCode())->toBe(1)
        ->and(swarmUpgradeCliReport($process)['status'])->toBe('preview')
        ->and(file_exists($this->upgradeCommandRoot.'/vendor'))->toBeFalse();
});

test('human preview exposes the same digest action IDs and version changes as JSON', function (): void {
    $report = swarmUpgradeCliReport(swarmUpgradeCli($this->upgradeCommandRoot, ['--json']));
    $process = swarmUpgradeCli($this->upgradeCommandRoot);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain($report['preview_digest']);

    foreach ($report['actions'] as $action) {
        expect($process->getOutput())->toContain($action['id'], $action['from'], $action['to']);
    }
});

test('standalone selection changes only the selected dependency and restores exact original bytes', function (): void {
    $lock = file_get_contents($this->upgradeCommandRoot.'/composer.lock');
    $report = swarmUpgradeCliReport(swarmUpgradeCli($this->upgradeCommandRoot, ['--json']));
    $action = collect($report['actions'])->firstWhere('package', 'builtbyberry/laravel-swarm-filament');
    expect($action)->not->toBeNull();

    $process = swarmUpgradeCli($this->upgradeCommandRoot, ['--json', '--apply='.$action['id'], '--expect='.$report['preview_digest'], '--yes']);
    $applied = swarmUpgradeCliReport($process);
    $manifest = json_decode(file_get_contents($this->upgradeCommandRoot.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(0)
        ->and($applied['status'])->toBe('applied')
        ->and($applied['runtime_verified'])->toBeFalse()
        ->and($applied['backup_id'])->toBeString()->not->toBeEmpty()
        ->and($manifest['require'])->toBe(json_decode($this->upgradeCommandManifest, true)['require'])
        ->and($manifest['require-dev']['builtbyberry/laravel-swarm-filament'])->toBe('0.2.3')
        ->and($manifest['scripts'])->toBe(['post-update-cmd' => 'do-not-execute-this-script'])
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.lock'))->toBe($lock);

    $restored = swarmUpgradeCli($this->upgradeCommandRoot, ['--json', '--restore='.$applied['backup_id'], '--yes']);
    expect($restored->getExitCode())->toBe(0)
        ->and(swarmUpgradeCliReport($restored)['status'])->toBe('restored')
        ->and(swarmUpgradeCliReport($restored)['runtime_verified'])->toBeFalse()
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.json'))->toBe($this->upgradeCommandManifest)
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.lock'))->toBe($lock);
});

test('standalone rejects missing confirmation digest and conflicting operations without writing', function (string $invalid): void {
    $preview = swarmUpgradeCliReport(swarmUpgradeCli($this->upgradeCommandRoot, ['--json']));
    $id = $preview['actions'][0]['id'];
    $options = match ($invalid) {
        'confirmation' => ['--apply='.$id, '--expect='.$preview['preview_digest']],
        'digest' => ['--apply='.$id, '--yes'],
        'conflict' => ['--apply='.$id, '--expect='.$preview['preview_digest'], '--restore=not-a-backup', '--yes'],
        'unknown-option' => ['--not-an-option'],
        'unknown-action' => ['--apply=not-an-action', '--expect='.$preview['preview_digest'], '--yes'],
    };
    $process = swarmUpgradeCli($this->upgradeCommandRoot, ['--json', ...$options]);

    expect($process->getExitCode())->toBe(2)
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.json'))->toBe($this->upgradeCommandManifest);
})->with(['confirmation', 'digest', 'conflict', 'unknown-option', 'unknown-action']);

test('Artisan registers the command and returns the same preview as the standalone entry point', function (): void {
    $expected = swarmUpgradeCliReport(swarmUpgradeCli($this->upgradeCommandRoot, ['--json']));
    $exit = Artisan::call('swarm:upgrade', ['--path' => $this->upgradeCommandRoot, '--json' => true]);
    $actual = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)->and($actual)->toBe($expected);

    $this->artisan('swarm:upgrade', ['--help' => true])->assertSuccessful();
});

test('Artisan guarded apply and restore use the same standalone preview contract', function (): void {
    $preview = swarmUpgradeCliReport(swarmUpgradeCli($this->upgradeCommandRoot, ['--json']));
    $id = collect($preview['actions'])->firstWhere('package', 'builtbyberry/laravel-swarm')['id'];
    $exit = Artisan::call('swarm:upgrade', ['--path' => $this->upgradeCommandRoot, '--json' => true, '--apply' => $id, '--expect' => $preview['preview_digest'], '--yes' => true]);
    $applied = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)->and($applied['status'])->toBe('applied');

    $exit = Artisan::call('swarm:upgrade', ['--path' => $this->upgradeCommandRoot, '--json' => true, '--restore' => $applied['backup_id'], '--yes' => true]);
    expect($exit)->toBe(0)
        ->and(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['status'])->toBe('restored')
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.json'))->toBe($this->upgradeCommandManifest);
});

function swarmNativeUpgradeCommandFixture(string $root): string
{
    $manifest = "{\r\n  \"require\": {\"builtbyberry/laravel-swarm\":\"^0.26.3\", \"laravel/ai\":\"^0.11.2\"},\r\n  \"scripts\": {\"post-update-cmd\":\"do-not-execute\"}, \"description\": \"keep café\"\r\n}\r\n";
    file_put_contents($root.'/composer.json', $manifest);
    file_put_contents($root.'/composer.lock', json_encode(['packages' => [
        ['name' => 'builtbyberry/laravel-swarm', 'version' => 'v0.26.3'],
        ['name' => 'laravel/ai', 'version' => 'v0.11.2'],
    ]], JSON_THROW_ON_ERROR));

    return $manifest;
}

test('native recipe standalone needs neither vendor nor executable target bootstrap', function (bool $hostile): void {
    $manifest = swarmNativeUpgradeCommandFixture($this->upgradeCommandRoot);
    $marker = $this->upgradeCommandRoot.'/executed';
    if ($hostile) {
        mkdir($this->upgradeCommandRoot.'/bootstrap');
        mkdir($this->upgradeCommandRoot.'/vendor/composer', 0700, true);
        $bomb = '<?php file_put_contents('.var_export($marker, true).', "executed"); throw new RuntimeException("must not boot");';
        foreach (['bootstrap/app.php', 'vendor/autoload.php', 'vendor/composer/installed.php'] as $file) {
            file_put_contents($this->upgradeCommandRoot.'/'.$file, $bomb);
        }
    }
    $files = count((new Filesystem)->allFiles($this->upgradeCommandRoot, true));
    $process = swarmUpgradeCli($this->upgradeCommandRoot, ['--recipe=0.26-to-0.27', '--json']);
    expect($process->getExitCode())->toBe(1)
        ->and(swarmUpgradeCliReport($process))->toMatchArray(['recipe' => '0.26-to-0.27', 'target' => '0.27.0', 'can_apply' => true, 'runtime_verified' => false])
        ->and(file_exists($marker))->toBeFalse()
        ->and(file_exists($this->upgradeCommandRoot.'/.swarm-upgrade'))->toBeFalse()
        ->and(count((new Filesystem)->allFiles($this->upgradeCommandRoot, true)))->toBe($files)
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.json'))->toBe($manifest);
})->with([false, true]);

test('native recipe identity and digest agree across human JSON Artisan apply and restore', function (): void {
    $manifest = swarmNativeUpgradeCommandFixture($this->upgradeCommandRoot);
    $lock = file_get_contents($this->upgradeCommandRoot.'/composer.lock');
    $options = ['--recipe=0.26-to-0.27'];
    $preview = swarmUpgradeCliReport(swarmUpgradeCli($this->upgradeCommandRoot, [...$options, '--json']));
    $human = swarmUpgradeCli($this->upgradeCommandRoot, $options);
    expect($human->getOutput())->toContain('recipe 0.26-to-0.27', 'target 0.27.0', $preview['preview_digest']);
    foreach ($preview['actions'] as $action) {
        expect($human->getOutput())->toContain($action['id'], $action['from'], $action['to']);
    }
    $artisanOptions = ['--path' => $this->upgradeCommandRoot, '--recipe' => '0.26-to-0.27', '--json' => true];
    expect(Artisan::call('swarm:upgrade', $artisanOptions))->toBe(1);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe($preview);
    expect(Artisan::call('swarm:upgrade', $artisanOptions + ['--apply' => 'dependency:builtbyberry/laravel-swarm', '--expect' => $preview['preview_digest'], '--yes' => true]))->toBe(0);
    $applied = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($applied)->toMatchArray(['recipe' => '0.26-to-0.27', 'target' => '0.27.0', 'status' => 'applied', 'runtime_verified' => false])
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.json'))->toBe(str_replace('"^0.26.3"', '"^0.27.0"', $manifest))
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.lock'))->toBe($lock);
    $after = file_get_contents($this->upgradeCommandRoot.'/composer.json');
    $wrong = swarmUpgradeCli($this->upgradeCommandRoot, ['--json', '--restore='.$applied['backup_id'], '--yes']);
    expect($wrong->getExitCode())->toBe(2)
        ->and(swarmUpgradeCliReport($wrong))->toMatchArray(['recipe' => '0.25-to-0.26', 'target' => '0.26.1', 'status' => 'error'])
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.json'))->toBe($after);
    $restore = swarmUpgradeCli($this->upgradeCommandRoot, [...$options, '--json', '--restore='.$applied['backup_id'], '--yes']);
    expect($restore->getExitCode())->toBe(0)
        ->and(swarmUpgradeCliReport($restore))->toMatchArray(['recipe' => '0.26-to-0.27', 'target' => '0.27.0', 'status' => 'restored', 'runtime_verified' => false])
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.json'))->toBe($manifest)
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.lock'))->toBe($lock);
});

test('native recipe selection survives standalone parse errors with truthful error identity', function (array $options, ?string $recipe, ?string $target): void {
    $manifest = swarmNativeUpgradeCommandFixture($this->upgradeCommandRoot);
    $process = swarmUpgradeCli($this->upgradeCommandRoot, ['--json', ...$options]);
    expect($process->getExitCode())->toBe(2)
        ->and(swarmUpgradeCliReport($process))->toMatchArray(['recipe' => $recipe, 'target' => $target, 'status' => 'error', 'runtime_verified' => false])
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.json'))->toBe($manifest)
        ->and(file_exists($this->upgradeCommandRoot.'/.swarm-upgrade'))->toBeFalse();
})->with([
    [['--recipe=unknown'], 'unknown', null],
    [['--recipe='], '', null],
    [['--recipe'], '', null],
    [['--recipe', '--json'], '', null],
    [['--unknown', '--recipe=0.26-to-0.27'], '0.26-to-0.27', '0.27.0'],
    [['--recipe=0.26-to-0.27', '--yes'], '0.26-to-0.27', '0.27.0'],
    [['--recipe=0.26-to-0.27', '--recipe=0.25-to-0.26'], null, null],
]);

test('shared selector validation rejects empty unknown and nonstring values without inventing a target', function (mixed $selector): void {
    $manifest = swarmNativeUpgradeCommandFixture($this->upgradeCommandRoot);
    $lines = [];
    $exit = (new UpgradeConsole)->run(['recipe' => $selector, 'json' => true], $this->upgradeCommandRoot, function (string $line) use (&$lines): void {
        $lines[] = $line;
    });
    $report = json_decode(implode("\n", $lines), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(2)->and($report)->toMatchArray(['recipe' => is_string($selector) ? $selector : null, 'target' => null, 'status' => 'error'])
        ->and(file_get_contents($this->upgradeCommandRoot.'/composer.json'))->toBe($manifest);
})->with(['', 'unknown', null, false, 1, [['0.26-to-0.27']]]);

test('Artisan and standalone share selector errors and describe their native parser distinction', function (): void {
    swarmNativeUpgradeCommandFixture($this->upgradeCommandRoot);
    foreach (['', 'unknown', '0.26-to-0.27'] as $selector) {
        $cli = swarmUpgradeCli($this->upgradeCommandRoot, ['--recipe='.$selector, '--json', '--yes']);
        expect(Artisan::call('swarm:upgrade', ['--path' => $this->upgradeCommandRoot, '--recipe' => $selector, '--json' => true, '--yes' => true]))->toBe(2);
        $artisan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $standalone = swarmUpgradeCliReport($cli);
        expect($artisan['recipe'])->toBe($standalone['recipe'])->and($artisan['target'])->toBe($standalone['target']);
    }
    $standaloneHelp = swarmUpgradeCli($this->upgradeCommandRoot, ['--help']);
    expect($standaloneHelp->getExitCode())->toBe(0)->and($standaloneHelp->getOutput())->toContain(UpgradeConsole::HELP);
    expect(Artisan::call('help', ['command_name' => 'swarm:upgrade']))->toBe(0);
    expect(Artisan::output())->toContain('0.25-to-0.26', '0.26-to-0.27', 'Standalone refuses repeated options', "Symfony's option parsing");
    // Symfony resolves repeated scalar options; no extra parser is layered on Artisan.
    expect(Artisan::call('swarm:upgrade --path='.$this->upgradeCommandRoot.' --recipe=0.25-to-0.26 --recipe=0.26-to-0.27 --json'))->toBe(1);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['recipe'])->toBe('0.26-to-0.27');
});
