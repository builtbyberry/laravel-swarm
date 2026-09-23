<?php

declare(strict_types=1);

/** @return list<string> */
function adoptionDependencyErrors(array $manifest, array $lock, array $installed, string $lane, array $expectedRefs = []): array
{
    $errors = [];
    $require = $manifest['require'] ?? [];
    if (($require['php'] ?? null) !== '^8.4' || ($require['laravel/ai'] ?? null) !== '^1.0') {
        $errors[] = 'Production requires PHP ^8.4 and official Laravel AI ^1.0.';
    }
    foreach (['bus', 'cache', 'concurrency', 'console', 'container', 'contracts', 'database', 'events', 'filesystem', 'json-schema', 'queue', 'support', 'view'] as $package) {
        if (($require['illuminate/'.$package] ?? null) !== ($package === 'json-schema' ? '^13.16' : '^13.0')) {
            $errors[] = 'Unexpected production Illuminate constraint: '.$package;
        }
    }
    if (($manifest['minimum-stability'] ?? null) !== 'stable' || ($manifest['prefer-stable'] ?? null) !== true || ! empty($manifest['repositories']) || ! empty($manifest['extra']['patches']) || isset($require['cweagans/composer-patches']) || isset($manifest['require-dev']['cweagans/composer-patches']) || ! empty($manifest['replace']) || ! empty($manifest['provide'])) {
        $errors[] = 'Production manifest must retain stable official resolution without repository or patch overrides.';
    }
    if (! in_array($lane, ['minimum', 'current', 'moving-dev', 'laravel-13.16'], true)) {
        $errors[] = 'Unknown dependency lane.';
    }
    if (! empty($lock['aliases'])) {
        $errors[] = 'Dependency proof must not use version aliases.';
    }
    $packages = array_column(array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []), null, 'name');
    $runtime = array_column($installed['packages'] ?? [], null, 'name');
    $sources = ['laravel/ai' => 'https://github.com/laravel/ai.git', 'laravel/framework' => 'https://github.com/laravel/framework.git'];
    if (($manifest['require-dev']['pestphp/pest'] ?? null) !== '^5.2.1' || ($manifest['require-dev']['pestphp/pest-plugin-laravel'] ?? null) !== '^5.0' || isset($manifest['require-dev']['laravel/framework'])) {
        $errors[] = 'Dependency lanes must restore the normal Pest 5 development manifest.';
    }
    if ($lane === 'laravel-13.16') {
        $sources += ['pestphp/pest' => 'https://github.com/pestphp/pest.git', 'pestphp/pest-plugin-laravel' => 'https://github.com/pestphp/pest-plugin-laravel.git'];
    }
    foreach ($sources as $name => $url) {
        foreach (['lock' => array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []), 'installed' => $installed['packages'] ?? []] as $kind => $entries) {
            if (count(array_filter($entries, fn (array $entry): bool => ($entry['name'] ?? null) === $name)) !== 1) {
                $errors[] = $name.' must have exactly one '.$kind.' package identity.';
            }
        }
        $p = $packages[$name] ?? [];
        $version = $p['version'] ?? '';
        $ref = $p['source']['reference'] ?? '';
        if (($p['source']['type'] ?? null) !== 'git' || ($p['source']['url'] ?? null) !== $url || ! preg_match('/^[a-f0-9]{40}$/D', $ref)) {
            $errors[] = $name.' must resolve official source and a full commit.';
        }
        if (($runtime[$name]['source']['type'] ?? null) !== 'git' || ($runtime[$name]['version'] ?? null) !== $version || ($runtime[$name]['source']['reference'] ?? null) !== $ref || ($runtime[$name]['source']['url'] ?? null) !== $url) {
            $errors[] = $name.' installed package must match the verified lock.';
        }
        if (str_starts_with($name, 'pestphp/')) {
            if (! preg_match('/^v?4\.\d+\.\d+$/D', $version) || version_compare(ltrim($version, 'v'), $name === 'pestphp/pest' ? '4.7.0' : '4.1.0', '<')) {
                $errors[] = $name.' compatibility lane must execute the supported Pest 4 toolchain.';
            }
        } elseif ($lane === 'moving-dev') {
            $expected = $expectedRefs[$name] ?? '';
            if (! is_string($expected) || ! preg_match('/^[a-f0-9]{40}$/D', $expected) || $ref !== $expected) {
                $errors[] = $name.' must match the captured official branch reference.';
            }
            if ($version !== ($name === 'laravel/ai' ? '1.x-dev' : '13.x-dev')) {
                $errors[] = $name.' did not resolve the exact compatible moving development branch.';
            }
        } elseif ($name === 'laravel/ai') {
            if (! preg_match('/^v?1\.\d+\.\d+$/D', $version) || version_compare(ltrim($version, 'v'), '1.0.0', '<')) {
                $errors[] = 'Stable AI must be >=1.0.0 and <2.0.0.';
            }
            if (in_array($lane, ['minimum', 'laravel-13.16'], true) && ($version !== 'v1.0.0' || $ref !== '101c7ea33cd8569d82570f753fbf38e48b7d3d95')) {
                $errors[] = 'Minimum lane must execute the exact official AI v1.0.0 release.';
            }
        } elseif (! preg_match('/^v?13\.\d+\.\d+$/D', $version)) {
            $errors[] = 'Stable framework must be Laravel 13.';
        }
        if ($lane === 'laravel-13.16' && $name === 'laravel/framework' && ($version !== 'v13.16.0' || $ref !== '66d5cdac5afd508dc6519ca59f5cc9b2c93a2b67')) {
            $errors[] = 'Compatibility lane must execute the exact official Laravel v13.16.0 release.';
        }
    }

    return $errors;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $lane = $argv[1] ?? '';
        $root = $argv[2] ?? getcwd();
        $manifestPath = $root.'/composer.json';
        if (isset($argv[3]) && file_get_contents($argv[3]) !== file_get_contents($manifestPath)) {
            throw new RuntimeException('Production manifest was not restored byte-for-byte before dependency verification.');
        }
        $read = fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $lock = $read($root.'/composer.lock');
        $errors = adoptionDependencyErrors($read($manifestPath), $lock, $read($root.'/vendor/composer/installed.json'), $lane, [
            'laravel/ai' => getenv('SWARM_AI_DEV_REF'),
            'laravel/framework' => getenv('SWARM_FRAMEWORK_DEV_REF'),
        ]);
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
            if (in_array($p['name'], ['laravel/ai', 'laravel/framework', 'orchestra/testbench', 'pestphp/pest', 'pestphp/pest-plugin-laravel'], true)) {
                echo $p['name'].' '.$p['version'].' '.($p['source']['url'] ?? '').' '.($p['source']['reference'] ?? '').PHP_EOL;
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(implode(PHP_EOL, $errors));
        }
        echo 'Verified '.$lane.' lock and installed source on PHP '.PHP_VERSION.PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage().PHP_EOL);
        exit(1);
    }
}
