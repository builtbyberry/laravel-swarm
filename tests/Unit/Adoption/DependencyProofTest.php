<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 3).'/.github/workflows/verify-adoption-dependencies.php';

function adoptionProofInputs(string $lane = 'minimum'): array
{
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $packages = [
        ['name' => 'laravel/ai', 'version' => $lane === 'moving-dev' ? '0.x-dev' : 'v0.11.2', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/ai.git', 'reference' => 'ee2c5162838d440c4e2e629ea93c8c87e838eaed']],
        ['name' => 'laravel/framework', 'version' => $lane === 'moving-dev' ? '13.x-dev' : 'v13.16.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/framework.git', 'reference' => str_repeat('a', 40)]],
    ];
    if ($lane === 'laravel-13.16') {
        $packages[1]['source']['reference'] = '66d5cdac5afd508dc6519ca59f5cc9b2c93a2b67';
        $packages[] = ['name' => 'pestphp/pest', 'version' => 'v4.7.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/pestphp/pest.git', 'reference' => str_repeat('b', 40)]];
        $packages[] = ['name' => 'pestphp/pest-plugin-laravel', 'version' => 'v4.1.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/pestphp/pest-plugin-laravel.git', 'reference' => str_repeat('c', 40)]];
    }

    return [$manifest, ['packages' => $packages], ['packages' => $packages], $lane];
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
        'ai-version' => $args[1]['packages'][0]['version'] = 'v0.11.3',
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
    ['ai-version', 'exact official AI v0.11.2'], ['ai-ref', 'exact official AI v0.11.2'],
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
    ['php', 'PHP ^8.4'], ['ai-floor', 'AI ^0.11.2'], ['illuminate', 'Illuminate constraint'],
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
})->with([['1.x-dev', str_repeat('a', 40)], ['dev-main', str_repeat('a', 40)], ['0.x-dev', 'moving']]);

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
    expect($commands)->toContain('"laravel/ai:0.x-dev"', '"laravel/framework:13.x-dev"', 'verify-adoption-dependencies.php moving-dev')
        ->not->toContain('1.x-dev', '|| true');
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
        'composer update --with laravel/ai:0.11.2 --prefer-lowest --prefer-stable --prefer-dist --no-interaction --no-progress',
        'cp /tmp/swarm-production-composer.json composer.json',
        'php .github/workflows/verify-adoption-dependencies.php laravel-13.16',
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
