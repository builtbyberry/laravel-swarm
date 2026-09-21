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

    return [$manifest, ['packages' => $packages], ['packages' => $packages], $lane];
}

it('accepts only the official supported production manifest and matching installed locks', function (string $lane) {
    expect(adoptionDependencyErrors(...adoptionProofInputs($lane)))->toBe([]);
})->with(['minimum', 'current', 'moving-dev']);

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
