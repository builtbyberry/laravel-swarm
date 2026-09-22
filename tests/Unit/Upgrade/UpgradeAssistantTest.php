<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Upgrade\JsonDocument;
use BuiltByBerry\LaravelSwarm\Upgrade\UpgradeAssistant;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->upgradeRoot = sys_get_temp_dir().'/swarm-upgrade-planner-'.bin2hex(random_bytes(8));
    mkdir($this->upgradeRoot, 0700);
    // A deliberately unusual, valid format exercises exact byte preservation.
    $this->upgradeManifest = "{\r\n\t\"require\" : {\"builtbyberry/laravel-swarm\": \"^0.25.0\", \"laravel/ai\":\"^0.10.3\"},\r\n\t\"description\": \"Keep \\\"quotes\\\" and Unicode \\u00e9\", \"extra\": [true, false, null, -1.2e+3, {\"version\":\"^0.25.0\"}]\r\n}\r\n";
    $this->upgradePackages = [
        ['name' => 'builtbyberry/laravel-swarm', 'version' => 'v0.25.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/builtbyberry/laravel-swarm.git', 'reference' => str_repeat('a', 40)]],
        ['name' => 'laravel/ai', 'version' => 'v0.10.3', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/ai.git', 'reference' => str_repeat('b', 40)]],
    ];
    file_put_contents($this->upgradeRoot.'/composer.json', $this->upgradeManifest);
    file_put_contents($this->upgradeRoot.'/composer.lock', json_encode(['packages' => $this->upgradePackages]));
    $this->upgradeAssistant = new UpgradeAssistant;
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->upgradeRoot);
});

test('recipe records missing installed proof without booting and leaves absent companions absent', function (): void {
    $report = $this->upgradeAssistant->inspect($this->upgradeRoot);
    expect($report['can_apply'])->toBeTrue()
        ->and(array_column($report['actions'], 'package'))->toBe(['builtbyberry/laravel-swarm', 'laravel/ai'])
        ->and(array_column($report['findings'], 'id'))->toContain('installed-unverified', 'approval-effects', 'rollback-reader', 'application-verification')
        ->and($report['runtime_verified'])->toBeFalse();
});

test('selected dependency patch preserves every unrelated byte and source constraint style', function (): void {
    $report = $this->upgradeAssistant->inspect($this->upgradeRoot);
    $this->upgradeAssistant->apply($this->upgradeRoot, ['dependency:builtbyberry/laravel-swarm'], $report['preview_digest']);
    expect(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe(str_replace('"^0.25.0",', '"^0.26.1",', $this->upgradeManifest));
    $new = $this->upgradeAssistant->inspect($this->upgradeRoot);
    expect(array_column($new['actions'], 'package'))->toBe(['laravel/ai']);
});

test('preview digest prevents edits after manifest lock or installed evidence changes', function (string $file): void {
    $report = $this->upgradeAssistant->inspect($this->upgradeRoot);
    if ($file === 'installed') {
        mkdir($this->upgradeRoot.'/vendor/composer', 0700, true);
        file_put_contents($this->upgradeRoot.'/vendor/composer/installed.json', json_encode(['packages' => $this->upgradePackages]));
    } else {
        file_put_contents($this->upgradeRoot.'/'.$file, "\n", FILE_APPEND);
    }
    $before = file_get_contents($this->upgradeRoot.'/composer.json');
    expect(fn () => $this->upgradeAssistant->apply($this->upgradeRoot, ['dependency:builtbyberry/laravel-swarm'], $report['preview_digest']))->toThrow(RuntimeException::class, 'stale')
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($before);
})->with(['composer.json', 'composer.lock', 'installed']);

test('missing malformed or unsupported locks never permit an inferred upgrade', function (string $case): void {
    if ($case === 'missing') {
        unlink($this->upgradeRoot.'/composer.lock');
    } elseif ($case === 'malformed') {
        file_put_contents($this->upgradeRoot.'/composer.lock', '{');
    } else {
        $this->upgradePackages[0]['version'] = 'v0.24.0';
        file_put_contents($this->upgradeRoot.'/composer.lock', json_encode(['packages' => $this->upgradePackages]));
    }
    $report = $this->upgradeAssistant->inspect($this->upgradeRoot);
    expect($report['can_apply'])->toBeFalse();
    expect(fn () => $this->upgradeAssistant->apply($this->upgradeRoot, ['dependency:builtbyberry/laravel-swarm'], $report['preview_digest']))->toThrow(RuntimeException::class, 'does not permit');
})->with(['missing', 'malformed', 'unsupported']);

test('installed version and source disagreements block mutation', function (string $field): void {
    mkdir($this->upgradeRoot.'/vendor/composer', 0700, true);
    if ($field === 'version') {
        $this->upgradePackages[0]['version'] = 'v0.26.0';
    } else {
        $this->upgradePackages[0]['source']['reference'] = str_repeat('c', 40);
    }
    file_put_contents($this->upgradeRoot.'/vendor/composer/installed.json', json_encode(['packages' => $this->upgradePackages]));
    $report = $this->upgradeAssistant->inspect($this->upgradeRoot);
    expect($report['can_apply'])->toBeFalse()
        ->and(array_column($report['findings'], 'id'))->toContain('installed-mismatch:builtbyberry/laravel-swarm');
})->with(['version', 'source']);

test('custom safe vendor directory is read as data', function (): void {
    $manifest = json_decode($this->upgradeManifest, true);
    $manifest['config']['vendor-dir'] = 'build/dependencies';
    file_put_contents($this->upgradeRoot.'/composer.json', json_encode($manifest));
    mkdir($this->upgradeRoot.'/build/dependencies/composer', 0700, true);
    file_put_contents($this->upgradeRoot.'/build/dependencies/composer/installed.json', json_encode(['packages' => $this->upgradePackages]));
    $report = $this->upgradeAssistant->inspect($this->upgradeRoot);
    expect($report['can_apply'])->toBeTrue()
        ->and($report['inventory']['builtbyberry/laravel-swarm']['installed'])->toBe('v0.25.0');
});

test('unsupported custom dependency policy blocks automatic edits', function (string $case): void {
    $manifest = json_decode($this->upgradeManifest, true);
    match ($case) {
        'constraint' => $manifest['require']['laravel/ai'] = '^0.10 || ^0.11',
        'repositories' => $manifest['repositories'] = [['type' => 'path', 'url' => '../fork']],
        'replace' => $manifest['replace'] = ['vendor/fork' => '*'],
        'platform' => $manifest['config']['platform']['php'] = '8.3.0',
    };
    file_put_contents($this->upgradeRoot.'/composer.json', json_encode($manifest));
    expect($this->upgradeAssistant->inspect($this->upgradeRoot)['can_apply'])->toBeFalse();
})->with(['constraint', 'repositories', 'replace', 'platform']);

test('out of root vendor paths and linked metadata are refused', function (string $case): void {
    if ($case === 'vendor-dir') {
        $manifest = json_decode($this->upgradeManifest, true);
        $manifest['config']['vendor-dir'] = '../vendor';
        file_put_contents($this->upgradeRoot.'/composer.json', json_encode($manifest));
    } else {
        rename($this->upgradeRoot.'/composer.lock', $this->upgradeRoot.'/real.lock');
        symlink($this->upgradeRoot.'/real.lock', $this->upgradeRoot.'/composer.lock');
    }
    expect(fn () => $this->upgradeAssistant->inspect($this->upgradeRoot))->toThrow(RuntimeException::class);
})->with(['vendor-dir', 'symlink']);

test('JSON duplicate escaped keys and malformed roots are rejected', function (string $json): void {
    expect(fn () => new JsonDocument($json))->toThrow(Exception::class);
})->with(['{"require":{},"requ\\u0069re":{}}', '{"extra":{"x":1,"x":2}}', '[]', '{']);

test('a current compatible caret is preserved and only lock resolution remains manual', function (): void {
    $manifest = json_decode($this->upgradeManifest, true);
    $manifest['require'] = ['builtbyberry/laravel-swarm' => '^0.26.0', 'laravel/ai' => '^0.11.2'];
    file_put_contents($this->upgradeRoot.'/composer.json', json_encode($manifest));
    $this->upgradePackages[0]['version'] = 'v0.26.0';
    $this->upgradePackages[1]['version'] = 'v0.11.2';
    file_put_contents($this->upgradeRoot.'/composer.lock', json_encode(['packages' => $this->upgradePackages]));
    $report = $this->upgradeAssistant->inspect($this->upgradeRoot);
    expect($report['actions'])->toBe([])->and($report['can_apply'])->toBeFalse()->and($report['runtime_verified'])->toBeFalse();
});

test('malformed manifest object shapes fail closed before a preview permits edits', function (string $field, mixed $value): void {
    $manifest = json_decode($this->upgradeManifest, true);
    $manifest[$field] = $value;
    file_put_contents($this->upgradeRoot.'/composer.json', json_encode($manifest));
    expect(fn () => $this->upgradeAssistant->inspect($this->upgradeRoot))->toThrow(RuntimeException::class, 'must be a JSON object');
})->with([
    ['config', 'invalid'], ['config', null], ['config', []],
    ['require-dev', null], ['require-dev', []], ['require', null],
    ['provide', false], ['replace', 'invalid'],
]);

test('malformed package container and source shapes produce blocking reports', function (string $case): void {
    $lock = ['packages' => $this->upgradePackages];
    match ($case) {
        'source-scalar' => $lock['packages'][0]['source'] = 'invalid',
        'source-list' => $lock['packages'][0]['source'] = [],
        'source-null' => $lock['packages'][0]['source'] = null,
        'source-incomplete' => $lock['packages'][0]['source'] = ['reference' => 'a'],
        'source-number' => $lock['packages'][0]['source']['reference'] = 12,
        'packages-object' => $lock['packages'] = (object) $this->upgradePackages,
        'dev-null' => $lock['packages-dev'] = null,
        'dev-object' => $lock['packages-dev'] = (object) [],
    };
    file_put_contents($this->upgradeRoot.'/composer.lock', json_encode($lock));
    $report = $this->upgradeAssistant->inspect($this->upgradeRoot);
    expect($report['can_apply'])->toBeFalse()
        ->and(array_column($report['findings'], 'id'))->toContain('invalid-lock');
})->with(['source-scalar', 'source-list', 'source-null', 'source-incomplete', 'source-number', 'packages-object', 'dev-null', 'dev-object']);

test('installed package object masquerading as a list blocks fixes', function (): void {
    mkdir($this->upgradeRoot.'/vendor/composer', 0700, true);
    file_put_contents($this->upgradeRoot.'/vendor/composer/installed.json', json_encode(['packages' => (object) $this->upgradePackages]));
    $report = $this->upgradeAssistant->inspect($this->upgradeRoot);
    expect($report['can_apply'])->toBeFalse()
        ->and(array_column($report['findings'], 'id'))->toContain('invalid-installed');
});
