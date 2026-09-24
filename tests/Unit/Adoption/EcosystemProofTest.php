<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function ecosystemProofFixture(): array
{
    $root = sys_get_temp_dir().'/swarm-ecosystem-'.bin2hex(random_bytes(8));
    mkdir($root.'/vendor/composer', 0777, true);
    $versions = [
        'builtbyberry/laravel-swarm' => ['0.27.0', str_repeat('a', 40)],
        'builtbyberry/laravel-swarm-pulse' => ['0.1.8', str_repeat('b', 40)],
        'builtbyberry/laravel-swarm-filament' => ['0.3.0', str_repeat('c', 40)],
        'builtbyberry/laravel-swarm-mcp' => ['0.2.0', str_repeat('d', 40)],
        'builtbyberry/laravel-swarm-memory-vector' => ['0.2.0', str_repeat('e', 40)],
        'laravel/ai' => ['1.0.0', '101c7ea33cd8569d82570f753fbf38e48b7d3d95'],
        'laravel/framework' => ['13.33.0', '91188a17ceaa3dbace6e8a5f7abd0d042e466359'],
        'laravel/mcp' => ['1.0.0', 'cfa4f38f82873eeb6848527883545f98f871e229'],
    ];
    $packages = $sources = [];
    foreach ($versions as $name => [$version, $ref]) {
        $packages[] = ['name' => $name, 'version' => $version, 'source' => ['type' => 'git', 'url' => 'https://github.com/'.$name.'.git', 'reference' => $ref], 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/'.$name.'/zipball/'.$ref, 'reference' => $ref]];
        if (str_starts_with($name, 'builtbyberry/')) {
            $sources[$name] = ['version' => $version, 'reference' => $ref];
        }
    }

    return [$root, ['packages' => $packages, 'aliases' => []], ['packages' => $packages], $sources];
}

it('accepts only exact complete lock and installed ecosystem identities', function (string $fault, string $message) {
    [$root, $lock, $installed, $sources] = ecosystemProofFixture();
    try {
        switch ($fault) {
            case 'version':
                $lock['packages'][0]['version'] = $installed['packages'][0]['version'] = '0.26.2';
                break;
            case 'reference':
                $lock['packages'][0]['source']['reference'] = $installed['packages'][0]['source']['reference'] = str_repeat('f', 40);
                break;
            case 'archive':
                $lock['packages'][0]['dist']['reference'] = $installed['packages'][0]['dist']['reference'] = str_repeat('f', 40);
                break;
            case 'missing':
                unset($lock['packages'][1]);
                $lock['packages'] = array_values($lock['packages']);
                break;
            case 'disagreement':
                $installed['packages'][0]['version'] = '0.26.2';
                break;
            case 'unofficial':
                $lock['packages'][0]['dist']['url'] = $installed['packages'][0]['dist']['url'] = 'https://example.com/archive.zip';
                break;
            case 'floating':
                $sources['builtbyberry/laravel-swarm']['reference'] = 'main';
                break;
            case 'incomplete-map':
                unset($sources['builtbyberry/laravel-swarm-pulse']);
                break;
            case 'aliases':
                $lock['aliases'] = [['alias' => '0.27.0']];
                break;
        }
        foreach (['composer.lock' => $lock, 'vendor/composer/installed.json' => $installed, 'sources.json' => $sources] as $path => $value) {
            file_put_contents($root.'/'.$path, json_encode($value, JSON_THROW_ON_ERROR));
        }
        $command = new Process(['python3', dirname(__DIR__, 3).'/.github/scripts/ai1-ecosystem/verify.py', '--app', $root, '--sources', $root.'/sources.json']);
        $command->run();
        expect($command->getExitCode())->toBe($fault === 'none' ? 0 : 1);
        expect($command->getOutput().$command->getErrorOutput())->toContain($message);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
})->with([
    'exact identity' => ['none', 'laravel/framework'],
    'wrong version' => ['version', 'Wrong version'],
    'wrong source' => ['reference', 'Wrong source ref'],
    'wrong archive' => ['archive', 'Wrong archive ref'],
    'missing companion' => ['missing', 'Missing locked/installed package'],
    'lock install disagreement' => ['disagreement', 'Lock/installed disagreement'],
    'unofficial archive' => ['unofficial', 'Unofficial archive'],
    'floating source' => ['floating', 'Immutable 40hex ref required'],
    'omitted expected companion' => ['incomplete-map', 'Expected exactly five ecosystem packages'],
    'alias' => ['aliases', 'Alias lock'],
]);
