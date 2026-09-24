<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 3).'/.github/workflows/verify-adoption-dependencies.php';

function adoptionProofInputs(string $lane = 'minimum'): array
{
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $packages = [
        ['name' => 'laravel/ai', 'version' => $lane === 'moving-dev' ? '1.x-dev' : 'v1.0.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/ai.git', 'reference' => '101c7ea33cd8569d82570f753fbf38e48b7d3d95']],
        ['name' => 'laravel/framework', 'version' => $lane === 'moving-dev' ? '13.x-dev' : 'v13.16.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/framework.git', 'reference' => str_repeat('a', 40)]],
    ];
    if ($lane === 'laravel-13.16') {
        $packages[1]['source']['reference'] = '66d5cdac5afd508dc6519ca59f5cc9b2c93a2b67';
        $packages[] = ['name' => 'pestphp/pest', 'version' => 'v4.7.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/pestphp/pest.git', 'reference' => str_repeat('b', 40)]];
        $packages[] = ['name' => 'pestphp/pest-plugin-laravel', 'version' => 'v4.1.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/pestphp/pest-plugin-laravel.git', 'reference' => str_repeat('c', 40)]];
    }

    // Expected observations are fixture inputs independent of the lock under test.
    return [$manifest, ['packages' => $packages], ['packages' => $packages], $lane, [
        'laravel/ai' => '101c7ea33cd8569d82570f753fbf38e48b7d3d95',
        'laravel/framework' => str_repeat('a', 40),
    ]];
}

it('accepts only the official supported production manifest and matching installed locks', function (string $lane) {
    expect(adoptionDependencyErrors(...adoptionProofInputs($lane)))->toBe([]);
})->with(['minimum', 'current', 'moving-dev', 'laravel-13.16']);

it('rejects compatibility proof with drifted runtime or test toolchain', function (string $mutation, string $expected) {
    $args = adoptionProofInputs('laravel-13.16');
    match ($mutation) {
        'framework-version' => $args[1]['packages'][1]['version'] = 'v13.23.0',
        'framework-ref' => $args[1]['packages'][1]['source']['reference'] = str_repeat('a', 40),
        'framework-source' => $args[1]['packages'][1]['source']['url'] = 'https://github.com/fork/framework.git',
        'ai-version' => $args[1]['packages'][0]['version'] = 'v1.0.1',
        'ai-ref' => $args[1]['packages'][0]['source']['reference'] = str_repeat('a', 40),
        'pest-major' => $args[1]['packages'][2]['version'] = 'v5.2.1',
        'pest-floor' => $args[1]['packages'][2]['version'] = 'v4.6.0',
        'plugin-major' => $args[1]['packages'][3]['version'] = 'v5.0.0',
        'plugin-floor' => $args[1]['packages'][3]['version'] = 'v4.0.0',
        'pest-source' => $args[1]['packages'][2]['source']['url'] = 'https://github.com/fork/pest.git',
        'plugin-ref' => $args[1]['packages'][3]['source']['reference'] = 'moving',
        'unrestored-pest' => $args[0]['require-dev']['pestphp/pest'] = '^4.7',
        'unrestored-plugin' => $args[0]['require-dev']['pestphp/pest-plugin-laravel'] = '^4.1',
        'unrestored-framework' => $args[0]['require-dev']['laravel/framework'] = '13.16.0',
    };
    // Matching installed metadata cannot rescue an invalid lock identity.
    $args[2] = $args[1];
    expect(implode(' ', adoptionDependencyErrors(...$args)))->toContain($expected);
})->with([
    ['framework-version', 'exact official Laravel v13.16.0'],
    ['framework-ref', 'exact official Laravel v13.16.0'],
    ['framework-source', 'official source'],
    ['ai-version', 'exact official AI v1.0.0'], ['ai-ref', 'exact official AI v1.0.0'],
    ['pest-major', 'supported Pest 4'], ['pest-floor', 'supported Pest 4'],
    ['plugin-major', 'supported Pest 4'], ['plugin-floor', 'supported Pest 4'],
    ['pest-source', 'official source'], ['plugin-ref', 'full commit'],
    ['unrestored-pest', 'restore the normal Pest 5'], ['unrestored-plugin', 'restore the normal Pest 5'],
    ['unrestored-framework', 'restore the normal Pest 5'],
]);

it('requires every compatibility dependency to be installed from its verified lock', function (int $package, string $mutation) {
    $args = adoptionProofInputs('laravel-13.16');
    if ($mutation === 'missing') {
        unset($args[2]['packages'][$package]);
    } else {
        $args[2]['packages'][$package]['source']['reference'] = str_repeat('d', 40);
    }
    expect(implode(' ', adoptionDependencyErrors(...$args)))->toContain('installed package must match');
})->with([0, 1, 2, 3])->with(['missing', 'mismatch']);

it('fails closed on a stable lock masquerading as moving development', function () {
    $args = adoptionProofInputs();
    $args[3] = 'moving-dev';
    expect(adoptionDependencyErrors(...$args))->toContain('laravel/ai did not resolve the exact compatible moving development branch.');
});

it('rejects changed production constraints and non official resolutions', function (string $mutation, string $expected) {
    $args = adoptionProofInputs();
    match ($mutation) {
        'php' => $args[0]['require']['php'] = '^8.3',
        'ai-floor' => $args[0]['require']['laravel/ai'] = '^0.10.3',
        'illuminate' => $args[0]['require']['illuminate/contracts'] = '^12.0',
        'json-schema' => $args[0]['require']['illuminate/json-schema'] = '^13.0',
        'repositories' => $args[0]['repositories'] = [['type' => 'vcs', 'url' => 'fork']],
        'patches' => $args[0]['extra']['patches'] = ['laravel/ai' => ['patch']],
        'stability' => $args[0]['minimum-stability'] = 'dev',
        'prefer-stable' => $args[0]['prefer-stable'] = false,
        'version' => $args[1]['packages'][0]['version'] = 'v0.10.3',
        'source' => $args[1]['packages'][0]['source']['url'] = 'https://github.com/fork/ai.git',
        'reference' => $args[1]['packages'][0]['source']['reference'] = str_repeat('b', 40),
        'installed' => $args[2]['packages'][0]['source']['reference'] = str_repeat('b', 40),
        'framework' => $args[1]['packages'][1]['version'] = 'v12.0.0',
        'unknown' => $args[3] = 'typo',
    };
    expect(implode(' ', adoptionDependencyErrors(...$args)))->toContain($expected);
})->with([
    ['php', 'PHP ^8.4'], ['ai-floor', 'AI ^1.0'], ['illuminate', 'Illuminate constraint'],
    ['json-schema', 'Illuminate constraint'], ['repositories', 'repository or patch'], ['patches', 'repository or patch'],
    ['stability', 'stable official'], ['prefer-stable', 'stable official'], ['version', 'Stable AI'],
    ['source', 'official source'], ['reference', 'exact official'], ['installed', 'installed package'],
    ['framework', 'Laravel 13'], ['unknown', 'Unknown dependency lane'],
]);

it('rejects other dev branches and malformed source refs', function (string $version, string $reference) {
    $args = adoptionProofInputs('moving-dev');
    $args[1]['packages'][0]['version'] = $version;
    $args[1]['packages'][0]['source']['reference'] = $reference;
    $args[2] = $args[1];
    expect(adoptionDependencyErrors(...$args))->not->toBe([]);
})->with([['0.x-dev', str_repeat('a', 40)], ['dev-main', str_repeat('a', 40)], ['1.x-dev', 'moving']]);

it('parses the actual nightly workflow and requires hard gates after verified moving resolution', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/nightly.yml');
    expect($workflow['on'])->toHaveKeys(['schedule', 'workflow_dispatch', 'pull_request']);
    $job = $workflow['jobs']['tests'];
    expect($job)->not->toHaveKey('continue-on-error');
    $runs = [];
    foreach ($job['steps'] as $step) {
        expect($step)->not->toHaveKeys(['continue-on-error', 'if']);
        if (isset($step['run'])) {
            $runs[] = $step['run'];
        }
    }
    $commands = implode("\n", $runs);
    expect($commands)->toContain('"laravel/ai:1.x-dev#${SWARM_AI_DEV_REF}"', '"laravel/framework:13.x-dev#${SWARM_FRAMEWORK_DEV_REF}"', 'verify-adoption-dependencies.php moving-dev')
        ->not->toContain('0.x-dev', '|| true');
    $verify = strpos($commands, 'verify-adoption-dependencies.php moving-dev');
    foreach (['composer test', 'composer test:process-concurrency:ci', 'composer analyse', 'composer test:compliance', 'composer lint'] as $gate) {
        expect($runs)->toContain($gate);
        expect(strpos($commands, $gate))->toBeGreaterThan($verify);
    }
});

it('preserves full Pest 5 coverage and unconditional Laravel 13.16 compatibility gates', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/tests.yml');
    expect($workflow['on'])->toHaveKeys(['push', 'pull_request']);
    $normal = $workflow['jobs']['tests'];
    expect($normal['strategy']['matrix'])->toBe(['php' => ['8.4', '8.5'], 'dependencies' => ['stable-latest', 'lowest']]);
    expect($normal)->not->toHaveKeys(['if', 'continue-on-error']);
    $coverage = array_values(array_filter($normal['steps'], fn (array $step): bool => ($step['run'] ?? '') === 'composer test:coverage:ci'));
    expect($coverage)->toHaveCount(1);
    expect($coverage[0])->not->toHaveKeys(['if', 'continue-on-error']);
    $normalSetup = array_values(array_filter($normal['steps'], fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@')))[0];
    expect($normalSetup['with']['coverage'])->toBe('pcov');
    expect($normalSetup['with']['ini-values'])->toBe('memory_limit=1G');
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['scripts']['test:coverage:ci'])->toBe('vendor/bin/pest tests/Feature tests/Unit tests/Installer --coverage --min=80');

    $job = $workflow['jobs']['laravel-13-16'];
    expect($job['strategy']['matrix'])->toBe(['php' => ['8.4', '8.5']]);
    expect($job)->not->toHaveKeys(['if', 'continue-on-error']);
    $runs = [];
    foreach ($job['steps'] as $step) {
        expect($step)->not->toHaveKeys(['if', 'continue-on-error']);
        if (isset($step['run'])) {
            $runs[] = $step['run'];
        }
        if (str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@')) {
            expect($step['with'])->toMatchArray(['php-version' => '${{ matrix.php }}', 'coverage' => 'none', 'ini-values' => 'memory_limit=1G']);
        }
    }
    $commands = implode("\n", $runs);
    expect($commands)->not->toContain('|| true', '--ignore-platform', '--no-security-blocking', '--coverage');
    $ordered = [
        'cp composer.json /tmp/swarm-production-composer.json',
        'composer require --no-update --dev "pestphp/pest:^4.7" "pestphp/pest-plugin-laravel:^4.1" "laravel/framework:13.16.0"',
        'composer update --with laravel/ai:1.0.0 --prefer-lowest --prefer-stable --prefer-dist --no-interaction --no-progress',
        'cp /tmp/swarm-production-composer.json composer.json',
        'php .github/workflows/verify-adoption-dependencies.php laravel-13.16 "$PWD" /tmp/swarm-production-composer.json',
        'composer test',
        'composer test:process-concurrency:ci',
        'composer analyse',
    ];
    $previous = -1;
    foreach ($ordered as $command) {
        expect($runs)->toContain($command);
        $position = array_search($command, $runs, true);
        expect($position)->toBeGreaterThan($previous);
        $previous = $position;
    }
});

it('rejects the same wrong well formed branch SHA in lock and installed metadata', function (int $package) {
    $args = adoptionProofInputs('moving-dev');
    $args[1]['packages'][$package]['source']['reference'] = str_repeat('d', 40);
    $args[2] = $args[1];

    expect(adoptionDependencyErrors(...$args))->toContain($args[1]['packages'][$package]['name'].' must match the captured official branch reference.');
})->with([0, 1]);

it('requires independently captured full expected branch refs', function (int $package, mixed $expected) {
    $args = adoptionProofInputs('moving-dev');
    $args[4][$args[1]['packages'][$package]['name']] = $expected;

    expect(implode(' ', adoptionDependencyErrors(...$args)))->toContain('captured official branch reference');
})->with([0, 1])->with([null, false, '', 'moving', 'abcd', str_repeat('f', 40)]);

it('rejects incorrect package identity or installed source even with matching versions', function (string $mutation) {
    $args = adoptionProofInputs('moving-dev');
    match ($mutation) {
        'lock-missing' => $args[1]['packages'] = [$args[1]['packages'][1]],
        'lock-duplicate' => $args[1]['packages'][] = $args[1]['packages'][0],
        'installed-duplicate' => $args[2]['packages'][] = $args[2]['packages'][0],
        'installed-name' => $args[2]['packages'][0]['name'] = 'fork/ai',
        'installed-url' => $args[2]['packages'][0]['source']['url'] = 'https://github.com/fork/ai.git',
        'installed-type' => $args[2]['packages'][0]['source']['type'] = 'path',
        'installed-version' => $args[2]['packages'][0]['version'] = 'v1.0.0',
        'lock-type' => $args[1]['packages'][0]['source']['type'] = 'path',
    };

    expect(adoptionDependencyErrors(...$args))->not->toBe([]);
})->with(['lock-missing', 'lock-duplicate', 'installed-duplicate', 'installed-name', 'installed-url', 'installed-type', 'installed-version', 'lock-type']);

it('rejects production aliases replacements and leftover job manifests in every lane', function (string $lane, string $mutation) {
    $args = adoptionProofInputs($lane);
    match ($mutation) {
        'require-alias' => $args[0]['require']['laravel/ai'] = '1.x-dev as 1.0.0',
        'replace' => $args[0]['replace'] = ['laravel/ai' => '*'],
        'provide' => $args[0]['provide'] = ['laravel/ai' => '*'],
        'patch-plugin' => $args[0]['require-dev']['cweagans/composer-patches'] = '*',
        'lock-alias' => $args[1]['aliases'] = [['package' => 'laravel/ai', 'version' => '1.x-dev', 'alias' => '1.0.0']],
        'pest' => $args[0]['require-dev']['pestphp/pest'] = '^4.7',
        'plugin' => $args[0]['require-dev']['pestphp/pest-plugin-laravel'] = '^4.1',
        'framework' => $args[0]['require-dev']['laravel/framework'] = '13.x-dev',
        'pinned-ai' => $args[0]['require']['laravel/ai'] = '1.x-dev#'.str_repeat('a', 40),
    };

    expect(adoptionDependencyErrors(...$args))->not->toBe([]);
})->with(['minimum', 'current', 'moving-dev', 'laravel-13.16'])->with(['require-alias', 'replace', 'provide', 'patch-plugin', 'lock-alias', 'pest', 'plugin', 'framework', 'pinned-ai']);

function adoptionProofInTemporaryDirectory(Closure $callback): void
{
    $directory = sys_get_temp_dir().'/swarm-dependency-proof-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    try {
        $callback($directory);
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}

it('checks the actual restored manifest and captured refs through the standalone verifier', function (string $mutation) {
    adoptionProofInTemporaryDirectory(function (string $directory) use ($mutation): void {
        [$manifest, $lock, $installed, $lane, $expected] = adoptionProofInputs('moving-dev');
        mkdir($directory.'/vendor/composer', 0700, true);
        file_put_contents($directory.'/original.json', json_encode($manifest));
        if ($mutation === 'unrestored') {
            $manifest['description'] = 'Job-only manifest was not restored';
        }
        if ($mutation === 'wrong-sha') {
            $lock['packages'][0]['source']['reference'] = str_repeat('d', 40);
            $installed = $lock;
        }
        file_put_contents($directory.'/composer.json', json_encode($manifest));
        file_put_contents($directory.'/composer.lock', json_encode($lock));
        file_put_contents($directory.'/vendor/composer/installed.json', json_encode($installed));
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/.github/workflows/verify-adoption-dependencies.php', $lane, $directory, $directory.'/original.json'], env: [
            'SWARM_AI_DEV_REF' => $expected['laravel/ai'],
            'SWARM_FRAMEWORK_DEV_REF' => $expected['laravel/framework'],
        ]);
        $process->run();
        expect($process->isSuccessful())->toBe($mutation === 'none');
        if ($mutation !== 'none') {
            expect($process->getErrorOutput())->toContain($mutation === 'unrestored' ? 'not restored byte-for-byte' : 'captured official branch reference');
        }
    });
})->with(['none', 'unrestored', 'wrong-sha']);

function adoptionMovingJobs(): array
{
    $root = dirname(__DIR__, 3).'/.github/workflows/';
    $nightly = Yaml::parseFile($root.'nightly.yml')['jobs']['tests'];
    $database = Yaml::parseFile($root.'tests-real-db.yml')['jobs'];

    return ['nightly' => $nightly] + $database;
}

it('captures official branch refs before pinning and restores manifests before verification in every moving lane', function () {
    foreach (adoptionMovingJobs() as $name => $job) {
        expect($job)->not->toHaveKeys(['if', 'continue-on-error']);
        $steps = array_column($job['steps'], null, 'name');
        $capture = $steps['Capture official moving branch references'];
        expect($capture['shell'])->toBe('bash');
        expect($capture)->not->toHaveKey('continue-on-error');
        expect($capture['run'])->toContain('git ls-remote --exit-code', 'https://github.com/laravel/ai.git refs/heads/1.x SWARM_AI_DEV_REF', 'https://github.com/laravel/framework.git refs/heads/13.x SWARM_FRAMEWORK_DEV_REF', 'date -u');
        $runs = array_values(array_filter(array_column($job['steps'], 'run')));
        $commands = implode("\n", $runs);
        foreach (['"laravel/ai:1.x-dev#${SWARM_AI_DEV_REF}"', '"laravel/framework:13.x-dev#${SWARM_FRAMEWORK_DEV_REF}"'] as $pin) {
            expect(strpos($commands, $pin))->toBeGreaterThan(strpos($commands, 'git ls-remote --exit-code'));
        }
        $restore = strpos($commands, 'cp /tmp/swarm-production-composer.json composer.json');
        $verify = strpos($commands, 'php .github/workflows/verify-adoption-dependencies.php');
        expect($restore)->toBeGreaterThan(strpos($commands, 'composer update'));
        expect($verify)->toBeGreaterThan($restore);
        expect($commands)->toContain('"$PWD" /tmp/swarm-production-composer.json')->not->toContain('|| true', '--ignore-platform', '0.x-dev');
        if ($name !== 'nightly') {
            expect($capture['if'])->toBe("matrix.dependencies == 'moving-dev'");
            expect($job['strategy']['matrix']['dependencies'])->toBe(['stable', 'moving-dev']);
            expect($job['services'])->toHaveKey($name === 'tests-real-db-mysql' ? 'mysql' : 'postgres');
            expect($steps['Execute real-DB process concurrency suite'])->not->toHaveKeys(['if', 'continue-on-error']);
            expect(strpos($commands, 'composer test:process-concurrency:real-db'))->toBeGreaterThan($verify);
        }
    }
});

it('executes every workflow branch-capture guard and fails closed on unavailable or ambiguous refs', function (string $case) {
    foreach (adoptionMovingJobs() as $job) {
        adoptionProofInTemporaryDirectory(function (string $directory) use ($job, $case): void {
            $steps = array_column($job['steps'], null, 'name');
            $capture = $steps['Capture official moving branch references']['run'];
            // The mock responds only to the exact official remote + branch pairs.
            file_put_contents($directory.'/git', <<<'BASH'
#!/usr/bin/env bash
set -eu
[[ "$1" == ls-remote && "$2" == --exit-code ]]
case "$3 $4" in
  'https://github.com/laravel/ai.git refs/heads/1.x') ref=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ;;
  'https://github.com/laravel/framework.git refs/heads/13.x') ref=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb ;;
  *) exit 3 ;;
esac
case "$SWARM_PROOF_CASE" in
  unavailable) exit 2 ;;
  empty) exit 0 ;;
  malformed) ref=not-a-sha ;;
  wrong-branch) printf '%s\trefs/heads/main\n' "$ref"; exit 0 ;;
  ambiguous) printf '%s\t%s\n' "$ref" "$4" ;;
esac
printf '%s\t%s\n' "$ref" "$4"
BASH);
            chmod($directory.'/git', 0700);
            file_put_contents($directory.'/environment', '');
            $process = new Process(['bash', '-c', $capture], env: [
                'PATH' => $directory.PATH_SEPARATOR.getenv('PATH'),
                'RUNNER_TEMP' => $directory,
                'GITHUB_ENV' => $directory.'/environment',
                'SWARM_PROOF_CASE' => $case,
            ]);
            $process->run();
            expect($process->isSuccessful())->toBe($case === 'valid');
            if ($case === 'valid') {
                expect(file_get_contents($directory.'/environment'))->toBe('SWARM_AI_DEV_REF='.str_repeat('a', 40)."\nSWARM_FRAMEWORK_DEV_REF=".str_repeat('b', 40)."\n");
                expect($process->getOutput())->toContain('Official branch refs observed at', 'refs/heads/1.x', 'refs/heads/13.x');
            }
        });
    }
})->with(['valid', 'unavailable', 'empty', 'malformed', 'wrong-branch', 'ambiguous']);

it('accepts current stable AI 1.x while refusing other majors prereleases and dev identities', function (string $version, bool $accepted) {
    $args = adoptionProofInputs('current');
    $args[1]['packages'][0]['version'] = $version;
    $args[2] = $args[1];
    expect(adoptionDependencyErrors(...$args) === [])->toBe($accepted);
})->with([['v1.0.0', true], ['v1.0.1', true], ['v1.2.3', true], ['v0.11.2', false], ['v2.0.0', false], ['v1.1.0-beta.1', false], ['1.x-dev', false]]);

it('retains process skips analysis memory compliance and advisory policy in the executable gates', function () {
    $root = dirname(__DIR__, 3);
    $manifest = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['scripts']['analyse'])->toContain('--memory-limit=2G');
    expect($manifest['scripts']['test:process-concurrency:ci'])->toContain('--fail-on-skipped');
    expect($manifest['scripts']['test:process-concurrency:real-db'])->toContain('--fail-on-skipped', '--group=skip-locked-real-db');
    expect($manifest['scripts']['test:compliance'])->toContain('--group=compliance', '--fail-on-skipped');
    $normal = Yaml::parseFile($root.'/.github/workflows/tests.yml')['jobs']['tests'];
    $steps = array_column($normal['steps'], null, 'name');
    foreach (['Execute tests with coverage', 'Execute process concurrency validation', 'Execute static analysis', 'Verify official dependency provenance'] as $name) {
        expect($steps[$name])->not->toHaveKeys(['if', 'continue-on-error']);
    }
    foreach (['Execute compliance gate', 'Execute code style check'] as $name) {
        expect($steps[$name]['if'])->toBe("matrix.dependencies == 'stable-latest'");
        expect($steps[$name])->not->toHaveKey('continue-on-error');
    }
    expect($steps['Install lowest dependencies']['run'])->toContain('--with laravel/ai:1.0.0');
    $audit = Yaml::parseFile($root.'/.github/workflows/audit.yml')['jobs']['audit'];
    expect($audit['continue-on-error'])->toBeTrue();
    expect(array_column($audit['strategy']['matrix']['resolution'], 'flags'))->toBe(['--prefer-stable', '--prefer-lowest --prefer-stable']);
    expect(array_column($audit['steps'], 'run'))->toContain('composer audit');
    $real = Yaml::parseFile($root.'/.github/workflows/tests-real-db.yml')['jobs'];
    expect($real['tests-real-db-mysql']['services']['mysql']['image'])->toBe('mysql:8');
    expect($real['tests-real-db-postgres']['services']['postgres']['image'])->toBe('postgres:16');
});
