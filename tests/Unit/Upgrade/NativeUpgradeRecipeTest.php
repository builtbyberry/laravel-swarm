<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Upgrade\UpgradeAssistant;
use BuiltByBerry\LaravelSwarm\Upgrade\UpgradeRecipe;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->nativeUpgradeRoot = sys_get_temp_dir().'/swarm-native-upgrade-'.bin2hex(random_bytes(8));
    mkdir($this->nativeUpgradeRoot.'/vendor/composer', 0700, true);
    $this->nativeManifest = "{\r\n\t\"require\": {\"builtbyberry/laravel-swarm\": \"^0.26.3\", \"laravel/ai\": \"0.11.2\"},\r\n\t\"description\": \"Unrelated ^0.26.3 café\"\r\n}\r\n";
    $this->nativePackages = [
        ['name' => 'builtbyberry/laravel-swarm', 'version' => 'v0.26.3', 'source' => ['type' => 'git', 'url' => 'https://github.com/builtbyberry/laravel-swarm.git', 'reference' => str_repeat('a', 40)]],
        ['name' => 'laravel/ai', 'version' => 'v0.11.2', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/ai.git', 'reference' => str_repeat('b', 40)]],
    ];
    file_put_contents($this->nativeUpgradeRoot.'/composer.json', $this->nativeManifest);
    $this->writeNativeMetadata = function (): void {
        $metadata = json_encode(['packages' => $this->nativePackages], JSON_THROW_ON_ERROR);
        file_put_contents($this->nativeUpgradeRoot.'/composer.lock', $metadata);
        file_put_contents($this->nativeUpgradeRoot.'/vendor/composer/installed.json', $metadata);
    };
    ($this->writeNativeMetadata)();
    $this->nativeAssistant = new UpgradeAssistant(new UpgradeRecipe(UpgradeRecipe::NATIVE_ONE));
    $this->nativeFiles = fn (): array => array_map(fn (string $path): string => file_get_contents($this->nativeUpgradeRoot.'/'.$path), ['composer.json', 'composer.lock', 'vendor/composer/installed.json']);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->nativeUpgradeRoot);
});

test('explicit native recipe changes only selected bytes and keeps default recipe unchanged', function (): void {
    $before = ($this->nativeFiles)();
    $default = (new UpgradeAssistant)->inspect($this->nativeUpgradeRoot);
    $explicitOld = (new UpgradeAssistant(new UpgradeRecipe(UpgradeRecipe::DEFAULT)))->inspect($this->nativeUpgradeRoot);
    expect($default)->toBe($explicitOld)->toMatchArray(['recipe' => '0.25-to-0.26', 'target' => '0.26.1', 'actions' => []]);
    $report = $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    expect($report)->toMatchArray(['recipe' => '0.26-to-0.27', 'target' => '0.27.0', 'can_apply' => true, 'runtime_verified' => false])
        ->and($report['actions'])->toBe([
            ['id' => 'dependency:builtbyberry/laravel-swarm', 'package' => 'builtbyberry/laravel-swarm', 'section' => 'require', 'from' => '^0.26.3', 'to' => '^0.27.0'],
            ['id' => 'dependency:laravel/ai', 'package' => 'laravel/ai', 'section' => 'require', 'from' => '0.11.2', 'to' => '1.0.0'],
        ])
        ->and(array_column($report['findings'], 'id'))->toContain('candidate-target', 'native-schema', 'native-privacy', 'native-authorization', 'rollback-reader', 'approval-effects')
        ->and(($this->nativeFiles)())->toBe($before);
    $applied = $this->nativeAssistant->apply($this->nativeUpgradeRoot, ['dependency:laravel/ai'], $report['preview_digest']);
    expect(($this->nativeFiles)())->toBe([str_replace('"0.11.2"', '"1.0.0"', $before[0]), $before[1], $before[2]])
        ->and($applied)->toMatchArray(['recipe' => '0.26-to-0.27', 'target' => '0.27.0', 'status' => 'applied', 'runtime_verified' => false]);
    $restored = $this->nativeAssistant->restore($this->nativeUpgradeRoot, $applied['backup_id']);
    expect(($this->nativeFiles)())->toBe($before)
        ->and($restored)->toMatchArray(['recipe' => '0.26-to-0.27', 'target' => '0.27.0', 'status' => 'restored', 'runtime_verified' => false]);
});

test('native recipe preserves already supported native major versions without lowering constraints', function (string $version, string $style): void {
    $manifest = json_decode($this->nativeManifest, true, flags: JSON_THROW_ON_ERROR);
    $manifest['require']['laravel/ai'] = $style.$version;
    file_put_contents($this->nativeUpgradeRoot.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $this->nativePackages[1]['version'] = 'v'.$version;
    ($this->writeNativeMetadata)();
    $report = $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    expect($report['can_apply'])->toBeTrue()
        ->and(array_column($report['actions'], 'package'))->toBe(['builtbyberry/laravel-swarm'])
        ->and(array_column($report['findings'], 'id'))->not->toContain('constraint-line:laravel/ai');
    $before = ($this->nativeFiles)();
    $this->nativeAssistant->apply($this->nativeUpgradeRoot, ['dependency:builtbyberry/laravel-swarm'], $report['preview_digest']);
    $after = ($this->nativeFiles)();
    expect(json_decode($after[0], true, flags: JSON_THROW_ON_ERROR)['require']['laravel/ai'])->toBe($style.$version)
        ->and(array_slice($after, 1))->toBe(array_slice($before, 1));
})->with(['1.0.0', '1.1.0', '1.12.3'])->with(['', '^']);

test('already target core receives verification only even when manifest constraints lag', function (string $version): void {
    $this->nativePackages[0]['version'] = $version;
    ($this->writeNativeMetadata)();
    $before = ($this->nativeFiles)();
    $report = $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    expect($report['actions'])->toBe([])->and($report['can_apply'])->toBeFalse()
        ->and(array_column($report['findings'], 'id'))->toContain('already-target')->not->toContain('unsupported-source');
    expect(fn () => $this->nativeAssistant->apply($this->nativeUpgradeRoot, ['dependency:builtbyberry/laravel-swarm'], $report['preview_digest']))->toThrow(RuntimeException::class, 'does not permit')
        ->and(($this->nativeFiles)())->toBe($before);
})->with(['v0.27.0', '0.27.9']);

test('native recipe refuses unsupported source lines without changing any input files', function (string $version): void {
    $this->nativePackages[0]['version'] = $version;
    ($this->writeNativeMetadata)();
    $before = ($this->nativeFiles)();
    $report = $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    expect($report['can_apply'])->toBeFalse()->and(array_column($report['findings'], 'id'))->toContain('unsupported-source');
    expect(fn () => $this->nativeAssistant->apply($this->nativeUpgradeRoot, ['dependency:builtbyberry/laravel-swarm'], $report['preview_digest']))->toThrow(RuntimeException::class, 'does not permit')
        ->and(($this->nativeFiles)())->toBe($before);
})->with(['v0.25.9', 'v0.28.0', '0.26.x-dev', 'dev-main', 'unknown']);

dataset('native companion versions', [
    'Pulse' => ['builtbyberry/laravel-swarm-pulse', '0.1.7', '0.1.8'],
    'Filament' => ['builtbyberry/laravel-swarm-filament', '0.2.3', '0.3.0'],
    'MCP' => ['builtbyberry/laravel-swarm-mcp', '0.1.2', '0.2.0'],
    'vector' => ['builtbyberry/laravel-swarm-memory-vector', '0.1.4', '0.2.0'],
]);

test('native companion targets preserve section style selection and exact backup bytes', function (string $package, string $source, string $target, string $section, string $style): void {
    $manifest = json_decode($this->nativeManifest, true, flags: JSON_THROW_ON_ERROR);
    $manifest[$section][$package] = $style.$source;
    $manifest['description'] = 'Keep '.$style.$source.' café';
    file_put_contents($this->nativeUpgradeRoot.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\r\n");
    $this->nativePackages[] = ['name' => $package, 'version' => 'v'.$source];
    ($this->writeNativeMetadata)();
    $before = ($this->nativeFiles)();
    $report = $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    $action = collect($report['actions'])->firstWhere('package', $package);
    $caretAlreadyPermitsTarget = $package === 'builtbyberry/laravel-swarm-pulse' && $style === '^';
    expect($report['can_apply'])->toBeTrue()
        ->and($report['runtime_verified'])->toBeFalse()
        ->and($report['inventory'][$package])->toMatchArray(['constraint' => $style.$source, 'locked' => 'v'.$source, 'installed' => 'v'.$source, 'recommended_minimum' => $target])
        ->and(array_column($report['findings'], 'id'))->toContain('resolve:'.$package)->not->toContain('companion-target-unresolved:'.$package)
        ->and(($this->nativeFiles)())->toBe($before);
    if ($caretAlreadyPermitsTarget) {
        expect($action)->toBeNull();
        $id = 'dependency:builtbyberry/laravel-swarm';
        $expected = str_replace('"^0.26.3"', '"^0.27.0"', $before[0]);
    } else {
        expect($action)->toBe(['id' => 'dependency:'.$package, 'package' => $package, 'section' => $section, 'from' => $style.$source, 'to' => $style.$target]);
        $id = $action['id'];
        $expected = str_replace('"'.$package.'": "'.$style.$source.'"', '"'.$package.'": "'.$style.$target.'"', $before[0]);
    }
    $applied = $this->nativeAssistant->apply($this->nativeUpgradeRoot, [$id], $report['preview_digest']);
    expect(($this->nativeFiles)())->toBe([$expected, $before[1], $before[2]])
        ->and($applied['runtime_verified'])->toBeFalse();
    $this->nativeAssistant->restore($this->nativeUpgradeRoot, $applied['backup_id']);
    expect(($this->nativeFiles)())->toBe($before);
})->with('native companion versions')->with(['require', 'require-dev'])->with(['', '^']);

test('native transitive companions receive inventory and Composer advice without direct addition', function (string $package, string $source, string $target): void {
    $this->nativePackages[] = ['name' => $package, 'version' => 'v'.$source];
    ($this->writeNativeMetadata)();
    $before = ($this->nativeFiles)();
    $report = $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    expect($report['can_apply'])->toBeTrue()
        ->and($report['inventory'][$package])->toMatchArray(['constraint' => null, 'locked' => 'v'.$source, 'installed' => 'v'.$source, 'recommended_minimum' => $target])
        ->and(array_column($report['actions'], 'package'))->toBe(['builtbyberry/laravel-swarm', 'laravel/ai'])
        ->and(array_column($report['findings'], 'id'))->toContain('resolve:'.$package, 'composer-resolution');
    $this->nativeAssistant->apply($this->nativeUpgradeRoot, array_column($report['actions'], 'id'), $report['preview_digest']);
    expect(($this->nativeFiles)())->toBe([str_replace(['"^0.26.3"', '"0.11.2"'], ['"^0.27.0"', '"1.0.0"'], $before[0]), $before[1], $before[2]]);
})->with('native companion versions');

test('native companions preserve current and newer supported target patches', function (string $package, string $source, string $target, bool $newer, string $style): void {
    $version = $newer ? substr($target, 0, strrpos($target, '.') + 1).'99' : $target;
    $manifest = json_decode($this->nativeManifest, true, flags: JSON_THROW_ON_ERROR);
    $manifest['require-dev'][$package] = $style.$version;
    file_put_contents($this->nativeUpgradeRoot.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $this->nativePackages[] = ['name' => $package, 'version' => 'v'.$version];
    ($this->writeNativeMetadata)();
    $before = ($this->nativeFiles)();
    $report = $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    expect($report['can_apply'])->toBeTrue()
        ->and(array_column($report['actions'], 'package'))->toBe(['builtbyberry/laravel-swarm', 'laravel/ai'])
        ->and(array_column($report['findings'], 'level'))->not->toContain('blocker');
    $this->nativeAssistant->apply($this->nativeUpgradeRoot, ['dependency:builtbyberry/laravel-swarm'], $report['preview_digest']);
    expect(($this->nativeFiles)())->toBe([str_replace('"^0.26.3"', '"^0.27.0"', $before[0]), $before[1], $before[2]]);
})->with('native companion versions')->with([false, true])->with(['', '^']);

test('native companion unsupported constraints and source lines refuse without changed bytes', function (string $package, string $source, string $target, string $case): void {
    $manifest = json_decode($this->nativeManifest, true, flags: JSON_THROW_ON_ERROR);
    $constraint = match ($case) {
        'complex' => '^'.$source.' || ^'.$target,
        'constraint-line' => '^0.9.0',
        default => '^'.$source,
    };
    if ($case !== 'transitive-source') {
        $manifest['require-dev'][$package] = $constraint;
    }
    file_put_contents($this->nativeUpgradeRoot.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $this->nativePackages[] = ['name' => $package, 'version' => in_array($case, ['source', 'transitive-source'], true) ? 'v0.9.0' : 'v'.$source];
    ($this->writeNativeMetadata)();
    $before = ($this->nativeFiles)();
    $report = $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    $finding = match ($case) {
        'complex' => 'constraint:',
        'constraint-line' => 'constraint-line:',
        default => 'source-line:',
    };
    expect($report['can_apply'])->toBeFalse()
        ->and(array_column($report['findings'], 'id'))->toContain($finding.$package);
    expect(fn () => $this->nativeAssistant->apply($this->nativeUpgradeRoot, ['dependency:builtbyberry/laravel-swarm'], $report['preview_digest']))->toThrow(RuntimeException::class, 'does not permit')
        ->and(($this->nativeFiles)())->toBe($before);
})->with('native companion versions')->with(['complex', 'constraint-line', 'source', 'transitive-source']);

test('native recipe never adds missing direct native or companion requirements', function (): void {
    $manifest = json_decode($this->nativeManifest, true, flags: JSON_THROW_ON_ERROR);
    unset($manifest['require']['laravel/ai']);
    file_put_contents($this->nativeUpgradeRoot.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $report = $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    expect(array_column($report['actions'], 'package'))->toBe(['builtbyberry/laravel-swarm']);
    $this->nativeAssistant->apply($this->nativeUpgradeRoot, array_column($report['actions'], 'id'), $report['preview_digest']);
    expect(array_keys(json_decode(file_get_contents($this->nativeUpgradeRoot.'/composer.json'), true, flags: JSON_THROW_ON_ERROR)['require']))->toBe(['builtbyberry/laravel-swarm']);
});

test('new recipe rejects stale and cross recipe digests before changing manifest bytes', function (string $case): void {
    $report = $case === 'recipe' ? (new UpgradeAssistant)->inspect($this->nativeUpgradeRoot) : $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    if ($case !== 'recipe') {
        file_put_contents($this->nativeUpgradeRoot.'/'.$case, "\n", FILE_APPEND);
    }
    $before = ($this->nativeFiles)();
    expect(fn () => $this->nativeAssistant->apply($this->nativeUpgradeRoot, ['dependency:builtbyberry/laravel-swarm'], $report['preview_digest']))->toThrow(RuntimeException::class, 'stale')
        ->and(($this->nativeFiles)())->toBe($before)
        ->and(glob($this->nativeUpgradeRoot.'/.swarm-upgrade/*.json'))->toBe([]);
})->with(['recipe', 'composer.json', 'composer.lock', 'vendor/composer/installed.json']);

test('digest explicitly includes the selected recipe and target identity', function (): void {
    $report = $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    $expected = hash('sha256', json_encode([
        '0.26-to-0.27', '0.27.0', realpath($this->nativeUpgradeRoot), ...($this->nativeFiles)(), $report['actions'], $report['findings'],
    ], JSON_THROW_ON_ERROR));
    expect($report['preview_digest'])->toBe($expected);
});

test('new recipe retains unsafe metadata and policy refusals with exact unchanged bytes', function (string $case): void {
    $manifest = json_decode($this->nativeManifest, true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode(file_get_contents($this->nativeUpgradeRoot.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $installed = $lock;
    match ($case) {
        'repository' => $manifest['repositories'] = [['type' => 'path', 'url' => '../fork']],
        'replace' => $manifest['replace'] = ['vendor/fork' => '*'],
        'provide' => $manifest['provide'] = ['vendor/fork' => '*'],
        'aliases' => $lock['aliases'] = [['package' => 'laravel/ai', 'alias' => '1.0.0']],
        'version-mismatch' => $installed['packages'][1]['version'] = '1.0.0',
        'source-mismatch' => $installed['packages'][1]['source']['reference'] = str_repeat('c', 40),
        'invalid-lock' => $lock['packages'][1]['source'] = null,
        'invalid-installed' => $installed['packages'] = (object) $installed['packages'],
        'constraint' => $manifest['require']['laravel/ai'] = '^0.11 || ^1.0',
        'constraint-line' => $manifest['require']['laravel/ai'] = '^2.0.0',
        'platform' => $manifest['config']['platform']['php'] = '8.3.0',
    };
    file_put_contents($this->nativeUpgradeRoot.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    file_put_contents($this->nativeUpgradeRoot.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    file_put_contents($this->nativeUpgradeRoot.'/vendor/composer/installed.json', json_encode($installed, JSON_THROW_ON_ERROR));
    $before = ($this->nativeFiles)();
    $report = $this->nativeAssistant->inspect($this->nativeUpgradeRoot);
    expect($report['can_apply'])->toBeFalse();
    expect(fn () => $this->nativeAssistant->apply($this->nativeUpgradeRoot, ['dependency:builtbyberry/laravel-swarm'], $report['preview_digest']))->toThrow(RuntimeException::class, 'does not permit')
        ->and(($this->nativeFiles)())->toBe($before);
})->with(['repository', 'replace', 'provide', 'aliases', 'version-mismatch', 'source-mismatch', 'invalid-lock', 'invalid-installed', 'constraint', 'constraint-line', 'platform']);

test('resolved native map leaves the default recipe target policy unchanged', function (): void {
    $default = new UpgradeRecipe;
    expect($default->target)->toBe('0.26.1')->and($default->packages)->toBe([
        'builtbyberry/laravel-swarm' => '0.26.1',
        'laravel/ai' => '0.11.2',
        'builtbyberry/laravel-swarm-pulse' => '0.1.7',
        'builtbyberry/laravel-swarm-filament' => '0.2.3',
        'builtbyberry/laravel-swarm-mcp' => '0.1.2',
        'builtbyberry/laravel-swarm-memory-vector' => '0.1.4',
    ]);
    expect($default->acceptsConstraint('builtbyberry/laravel-swarm-filament', '0.3.0'))->toBeFalse()
        ->and($default->acceptsConstraint('builtbyberry/laravel-swarm-mcp', '0.2.0'))->toBeFalse()
        ->and($default->acceptsConstraint('builtbyberry/laravel-swarm-memory-vector', '0.2.0'))->toBeFalse();
});
