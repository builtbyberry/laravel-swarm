<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 3).'/.github/workflows/verify-adoption-dependencies.php';

function adoptionProofInputs(string $lane = 'minimum'): array
{
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $packages = [
        ['name' => 'laravel/ai', 'version' => 'v1.0.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/ai.git', 'reference' => '101c7ea33cd8569d82570f753fbf38e48b7d3d95']],
        // Stable lanes carry the exact floor release; the moving lane carries the captured branch head.
        ['name' => 'laravel/framework', 'version' => $lane === 'moving-dev' ? '13.x-dev' : 'v13.40.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/framework.git', 'reference' => $lane === 'moving-dev' ? str_repeat('a', 40) : '1449bf241017087fbd38c118ecaabef3f6797fdc']],
    ];

    // Expected observations are fixture inputs independent of the lock under test.
    return [$manifest, ['packages' => $packages], ['packages' => $packages], $lane, [
        'laravel/framework' => str_repeat('a', 40),
    ]];
}

it('accepts only the official supported production manifest and matching installed locks', function (string $lane) {
    expect(adoptionDependencyErrors(...adoptionProofInputs($lane)))->toBe([]);
})->with(['minimum', 'current', 'moving-dev']);

it('rejects a minimum lane that does not execute the exact declared floor', function (string $mutation, string $expected) {
    $args = adoptionProofInputs('minimum');
    match ($mutation) {
        'framework-version' => $args[1]['packages'][1]['version'] = 'v13.41.0',
        'framework-below-floor' => $args[1]['packages'][1]['version'] = 'v13.39.0',
        'framework-ref' => $args[1]['packages'][1]['source']['reference'] = str_repeat('a', 40),
        'framework-source' => $args[1]['packages'][1]['source']['url'] = 'https://github.com/fork/framework.git',
        'ai-version' => $args[1]['packages'][0]['version'] = 'v1.0.1',
        'ai-ref' => $args[1]['packages'][0]['source']['reference'] = str_repeat('a', 40),
        'unrestored-pest' => $args[0]['require-dev']['pestphp/pest'] = '^4.7',
        'unrestored-plugin' => $args[0]['require-dev']['pestphp/pest-plugin-laravel'] = '^4.1',
        'unrestored-framework' => $args[0]['require-dev']['laravel/framework'] = '13.40.0',
    };
    // Matching installed metadata cannot rescue an invalid lock identity.
    $args[2] = $args[1];
    expect(implode(' ', adoptionDependencyErrors(...$args)))->toContain($expected);
})->with([
    ['framework-version', 'exact official Laravel v13.40.0'],
    ['framework-below-floor', 'exact official Laravel v13.40.0'],
    ['framework-ref', 'exact official Laravel v13.40.0'],
    ['framework-source', 'official source'],
    ['ai-version', 'exact official AI v1.0.0'], ['ai-ref', 'exact official AI v1.0.0'],
    ['unrestored-pest', 'restore the normal Pest 5'], ['unrestored-plugin', 'restore the normal Pest 5'],
    ['unrestored-framework', 'restore the normal Pest 5'],
]);

it('holds only the minimum lane to the exact floor release', function () {
    $args = adoptionProofInputs('current');
    $args[1]['packages'][1]['version'] = 'v13.43.0';
    $args[1]['packages'][1]['source']['reference'] = str_repeat('f', 40);
    $args[2] = $args[1];
    expect(adoptionDependencyErrors(...$args))->toBe([]);

    $args[3] = 'minimum';
    expect(adoptionDependencyErrors(...$args))->toBe(['Minimum lane must execute the exact official Laravel v13.40.0 release.']);
});

it('requires every minimum dependency to be installed from its verified lock', function (int $package, string $mutation) {
    $args = adoptionProofInputs('minimum');
    if ($mutation === 'missing') {
        unset($args[2]['packages'][$package]);
    } else {
        $args[2]['packages'][$package]['source']['reference'] = str_repeat('d', 40);
    }
    expect(implode(' ', adoptionDependencyErrors(...$args)))->toContain('installed package must match');
})->with([0, 1])->with(['missing', 'mismatch']);

it('fails closed on a stable lock masquerading as moving development', function () {
    $args = adoptionProofInputs();
    $args[3] = 'moving-dev';
    expect(adoptionDependencyErrors(...$args))->toContain('laravel/framework did not resolve the exact compatible moving development branch.');
});

it('rejects changed production constraints and non official resolutions', function (string $mutation, string $expected) {
    $args = adoptionProofInputs();
    match ($mutation) {
        'php' => $args[0]['require']['php'] = '^8.3',
        'ai-floor' => $args[0]['require']['laravel/ai'] = '^0.10.3',
        'illuminate' => $args[0]['require']['illuminate/contracts'] = '^12.0',
        'json-schema' => $args[0]['require']['illuminate/json-schema'] = '^13.0',
        'concurrency' => $args[0]['require']['illuminate/concurrency'] = '^13.0',
        'concurrency-old-floor' => $args[0]['require']['illuminate/concurrency'] = '^13.16',
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
    ['json-schema', 'Illuminate constraint'], ['concurrency', 'Illuminate constraint: concurrency'],
    ['concurrency-old-floor', 'Illuminate constraint: concurrency'], ['repositories', 'repository or patch'], ['patches', 'repository or patch'],
    ['stability', 'stable official'], ['prefer-stable', 'stable official'], ['version', 'Stable AI'],
    ['source', 'official source'], ['reference', 'exact official'], ['installed', 'installed package'],
    ['framework', 'Laravel 13'], ['unknown', 'Unknown dependency lane'],
]);

it('rejects a laravel/ai development branch in the moving lane even at a full commit', function (string $version, string $reference) {
    $args = adoptionProofInputs('moving-dev');
    $args[1]['packages'][0]['version'] = $version;
    $args[1]['packages'][0]['source']['reference'] = $reference;
    $args[2] = $args[1];
    expect(adoptionDependencyErrors(...$args))->toContain('Stable AI must be >=1.0.0 and <2.0.0.');
})->with([['1.x-dev', str_repeat('a', 40)], ['0.x-dev', str_repeat('a', 40)], ['dev-main', str_repeat('a', 40)], ['1.x-dev', 'moving']]);

it('parses the actual nightly workflow and requires hard gates after verified moving resolution', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/nightly.yml');
    expect($workflow['on'])->toHaveKeys(['schedule', 'workflow_dispatch', 'pull_request']);
    $job = $workflow['jobs']['tests'];
    expect($job)->not->toHaveKey('continue-on-error');
    $setup = array_values(array_filter($job['steps'], fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@')))[0];
    expect($setup['with'])->toMatchArray(['php-version' => '8.5', 'coverage' => 'none', 'ini-values' => 'memory_limit=1G']);
    $runs = [];
    foreach ($job['steps'] as $step) {
        expect($step)->not->toHaveKeys(['continue-on-error', 'if']);
        if (isset($step['run'])) {
            $runs[] = $step['run'];
        }
    }
    $commands = implode("\n", $runs);
    expect($job['name'])->toBe('PHP 8.5 - Laravel 13.x-dev / AI stable');
    expect($commands)->toContain('"laravel/framework:13.x-dev#${SWARM_FRAMEWORK_DEV_REF}"', 'verify-adoption-dependencies.php moving-dev')
        // One needle per call: a negated multi-needle toContain passes when any needle is absent.
        ->not->toContain('laravel/ai:')->not->toContain('SWARM_AI_DEV_REF')->not->toContain('1.x-dev')
        ->not->toContain('0.x-dev')->not->toContain('|| true');
    $verify = strpos($commands, 'verify-adoption-dependencies.php moving-dev');
    foreach (['composer test:ci', 'composer test:process-concurrency:ci', 'composer analyse', 'composer test:compliance', 'composer lint'] as $gate) {
        expect($runs)->toContain($gate);
        expect(strpos($commands, $gate))->toBeGreaterThan($verify);
    }
});

it('preserves full Pest 5 coverage and proves the Laravel floor on the lowest lane without advisory overrides', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/tests.yml');
    expect($workflow['on'])->toHaveKeys(['push', 'pull_request']);
    $normal = $workflow['jobs']['tests'];
    expect($normal['strategy']['matrix'])->toBe(['php' => ['8.4', '8.5'], 'dependencies' => ['stable-latest', 'lowest']]);
    expect($normal)->not->toHaveKeys(['if', 'continue-on-error']);
    $coverage = array_values(array_filter($normal['steps'], fn (array $step): bool => ($step['run'] ?? '') === 'composer test:coverage:ci'));
    expect($coverage)->toHaveCount(1);
    expect($coverage[0])->not->toHaveKeys(['if', 'continue-on-error']);
    $normalSetup = array_values(array_filter($normal['steps'], fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@')))[0];
    expect($normalSetup['with']['coverage'])->toBe('xdebug');
    expect($normalSetup['with']['ini-values'])->toBe('memory_limit=1G');
    $normalCommands = implode("\n", array_column($normal['steps'], 'run'));
    expect($normalCommands)->not->toContain('policy.advisories.ignore', 'policy.advisories.block');
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $productionAdvisoryPolicy = $manifest['config']['policy']['advisories'] ?? [];
    expect($productionAdvisoryPolicy)->not->toHaveKeys(['ignore-id', 'ignore', 'block']);
    expect($manifest['scripts']['test'])->toBe('vendor/bin/pest tests/Feature tests/Unit tests/Installer')
        ->and($manifest['scripts']['test:ci'])->toBe([
            'vendor/bin/pest --parallel --processes=4 --max-batch-size=1 --exclude-group=ci-serial',
            '@php -d memory_limit=512M vendor/bin/pest --group=ci-serial',
        ])
        ->and($manifest['scripts']['test:coverage:ci'])->toBe([
            'vendor/bin/pest --parallel --processes=4 --max-batch-size=1 --exclude-group=ci-serial --coverage --min=80',
            '@php -d memory_limit=512M vendor/bin/pest --group=ci-serial',
        ]);
    foreach (['test:ci', 'test:coverage:ci'] as $script) {
        [$parallel, $serial] = $manifest['scripts'][$script];
        expect($parallel)->toContain('--parallel', '--processes=4', '--max-batch-size=1', '--exclude-group=ci-serial')
            ->and($serial)->toBe('@php -d memory_limit=512M vendor/bin/pest --group=ci-serial')
            ->and($serial)->not->toContain('--parallel', '--coverage');
    }
    expect($manifest['scripts']['test:coverage:ci'][0])->toContain('--coverage --min=80')
        ->and($manifest['scripts']['test:ci'][0])->not->toContain('--coverage');

    $testsRoot = dirname(__DIR__, 2);
    $serialTests = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getPathname() === __FILE__) {
            continue;
        }
        if (str_contains(file_get_contents($file->getPathname()), 'ci-serial')) {
            $relativePath = str_replace($testsRoot.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $serialTests[] = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        }
    }
    expect($serialTests)->toBe(['Feature/ProviderTools/ProviderToolPreservationTest.php'])
        ->and(file_get_contents($testsRoot.'/Feature/ProviderTools/ProviderToolPreservationTest.php'))
        ->toContain("pest()->group('ci-serial');");
    $phpunit = file_get_contents(dirname(__DIR__, 3).'/phpunit.xml');
    preg_match_all('#<directory>tests/(Unit|Feature|Installer)</directory>#', $phpunit, $suiteMatches);
    expect(substr_count($phpunit, '<directory>'))->toBe(3)
        ->and($suiteMatches[1])->toBe(['Unit', 'Feature', 'Installer']);
    foreach (['MakeSwarmCommandTest.php', 'MakeSwarmSwarmCommandTest.php', 'MakeSwarmAgentCommandTest.php', 'MakeMemoryToolCommandTest.php'] as $generatorTest) {
        expect(file_get_contents(dirname(__DIR__, 2).'/Feature/'.$generatorTest))
            ->not->toContain("glob(app_path('Ai/");
    }

    // The lowest matrix rows are the floor proof. A second job here used to pin an older Laravel
    // with advisories ignored (CVE-2026-128083 ended that); a new job belongs in its own workflow,
    // or this list is updated on purpose.
    expect(array_keys($workflow['jobs']))->toBe(['tests']);
    $runs = array_values(array_filter(array_column($normal['steps'], 'run')));
    $ordered = [
        'composer update --with laravel/ai:1.0.0 --prefer-lowest --prefer-stable --prefer-dist --no-interaction --no-progress',
        "php .github/workflows/verify-adoption-dependencies.php \${{ matrix.dependencies == 'lowest' && 'minimum' || 'current' }}",
        'composer test:coverage:ci',
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
    // No workflow may ignore a security advisory or bring back the retired 13.16 floor.
    $workflowFiles = array_merge(
        glob(dirname(__DIR__, 3).'/.github/workflows/*.yml') ?: [],
        glob(dirname(__DIR__, 3).'/.github/workflows/*.yaml') ?: [],
    );
    expect(count($workflowFiles))->toBeGreaterThan(1);
    foreach ($workflowFiles as $workflowFile) {
        // One needle per call: a negated multi-needle toContain passes when any needle is absent.
        expect(file_get_contents($workflowFile))
            ->not->toContain('policy.advisories')
            ->not->toContain('--no-security-blocking')
            ->not->toContain('13.16');
    }
});

it('keeps mutation coverage on the hosted stable driver without weakening its baseline', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/mutation.yml');
    expect($workflow['on'])->toHaveKeys(['schedule', 'workflow_dispatch']);
    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    $job = $workflow['jobs']['mutate'];
    expect($job['continue-on-error'])->toBeTrue()
        ->and($job['timeout-minutes'])->toBe(240);
    $steps = array_column($job['steps'], null, 'name');
    expect($steps['Setup PHP']['with'])->toMatchArray([
        'php-version' => '8.5',
        'coverage' => 'xdebug',
        'ini-values' => 'memory_limit=1G',
    ]);
    expect($steps['Run Pest mutation testing']['run'])->toBe('composer test:mutation');
    expect(file_get_contents(dirname(__DIR__, 3).'/docs/maintenance.md'))
        ->toContain('with Xdebug, the same hosted coverage driver as the ordinary coverage gate.')
        ->toContain('Timeout is 240');
});

it('rejects the same wrong well formed branch SHA in lock and installed metadata', function (int $package) {
    $args = adoptionProofInputs('moving-dev');
    $args[1]['packages'][$package]['source']['reference'] = str_repeat('d', 40);
    $args[2] = $args[1];

    expect(adoptionDependencyErrors(...$args))->toContain($args[1]['packages'][$package]['name'].' must match the captured official branch reference.');
})->with([1]);

it('requires independently captured full expected branch refs', function (int $package, mixed $expected) {
    $args = adoptionProofInputs('moving-dev');
    $args[4][$args[1]['packages'][$package]['name']] = $expected;

    expect(implode(' ', adoptionDependencyErrors(...$args)))->toContain('captured official branch reference');
})->with([1])->with([null, false, '', 'moving', 'abcd', str_repeat('f', 40)]);

it('rejects incorrect package identity or installed source even with matching versions', function (string $mutation) {
    $args = adoptionProofInputs('moving-dev');
    match ($mutation) {
        'lock-missing' => $args[1]['packages'] = [$args[1]['packages'][1]],
        'lock-duplicate' => $args[1]['packages'][] = $args[1]['packages'][0],
        'installed-duplicate' => $args[2]['packages'][] = $args[2]['packages'][0],
        'installed-name' => $args[2]['packages'][0]['name'] = 'fork/ai',
        'installed-url' => $args[2]['packages'][0]['source']['url'] = 'https://github.com/fork/ai.git',
        'installed-type' => $args[2]['packages'][0]['source']['type'] = 'path',
        'installed-version' => $args[2]['packages'][0]['version'] = 'v1.0.1',
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
})->with(['minimum', 'current', 'moving-dev'])->with(['require-alias', 'replace', 'provide', 'patch-plugin', 'lock-alias', 'pest', 'plugin', 'framework', 'pinned-ai']);

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
            $lock['packages'][1]['source']['reference'] = str_repeat('d', 40);
            $installed = $lock;
        }
        file_put_contents($directory.'/composer.json', json_encode($manifest));
        file_put_contents($directory.'/composer.lock', json_encode($lock));
        file_put_contents($directory.'/vendor/composer/installed.json', json_encode($installed));
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/.github/workflows/verify-adoption-dependencies.php', $lane, $directory, $directory.'/original.json'], env: [
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
        expect($capture['run'])->toContain('git ls-remote --exit-code', 'https://github.com/laravel/framework.git refs/heads/13.x SWARM_FRAMEWORK_DEV_REF', 'date -u')
            ->not->toContain('laravel/ai.git')->not->toContain('SWARM_AI_DEV_REF');
        $runs = array_values(array_filter(array_column($job['steps'], 'run')));
        $commands = implode("\n", $runs);
        expect(strpos($commands, '"laravel/framework:13.x-dev#${SWARM_FRAMEWORK_DEV_REF}"'))->toBeGreaterThan(strpos($commands, 'git ls-remote --exit-code'));
        // The moving lanes take laravel/ai's latest stable release, never its development branch.
        expect($commands)->not->toContain('laravel/ai:')->not->toContain('SWARM_AI_DEV_REF')->not->toContain('1.x-dev');
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
                expect(file_get_contents($directory.'/environment'))->toBe('SWARM_FRAMEWORK_DEV_REF='.str_repeat('b', 40)."\n");
                expect($process->getOutput())->toContain('Official branch refs observed at', 'refs/heads/13.x')->not->toContain('refs/heads/1.x');
            }
        });
    }
})->with(['valid', 'unavailable', 'empty', 'malformed', 'wrong-branch', 'ambiguous']);

it('accepts current stable AI 1.x while refusing other majors prereleases and dev identities', function (string $lane, string $version, bool $accepted) {
    $args = adoptionProofInputs($lane);
    $args[1]['packages'][0]['version'] = $version;
    if ($version !== 'v1.0.0') {
        // Any stable 1.x release at its own commit, not only the v1.0.0 floor the minimum lane pins.
        $args[1]['packages'][0]['source']['reference'] = str_repeat('e', 40);
    }
    $args[2] = $args[1];
    expect(adoptionDependencyErrors(...$args) === [])->toBe($accepted);
})->with(['current', 'moving-dev'])->with([['v1.0.0', true], ['v1.0.1', true], ['v1.2.3', true], ['v1.3.0', true], ['v0.11.2', false], ['v2.0.0', false], ['v1.1.0-beta.1', false], ['1.x-dev', false]]);

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
