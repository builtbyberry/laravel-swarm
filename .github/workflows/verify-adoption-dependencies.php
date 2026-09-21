<?php

declare(strict_types=1);

/** @return list<string> */
function adoptionDependencyErrors(array $manifest, array $lock, array $installed, string $lane): array
{
    $errors = [];
    $require = $manifest['require'] ?? [];
    if (($require['php'] ?? null) !== '^8.4' || ($require['laravel/ai'] ?? null) !== '^0.11.2') {
        $errors[] = 'Production requires PHP ^8.4 and official Laravel AI ^0.11.2.';
    }
    foreach (['bus', 'cache', 'concurrency', 'console', 'container', 'contracts', 'database', 'events', 'filesystem', 'json-schema', 'queue', 'support', 'view'] as $package) {
        if (($require['illuminate/'.$package] ?? null) !== ($package === 'json-schema' ? '^13.16' : '^13.0')) {
            $errors[] = 'Unexpected production Illuminate constraint: '.$package;
        }
    }
    if (($manifest['minimum-stability'] ?? null) !== 'stable' || ($manifest['prefer-stable'] ?? null) !== true || ! empty($manifest['repositories']) || ! empty($manifest['extra']['patches']) || isset($require['cweagans/composer-patches'])) {
        $errors[] = 'Production manifest must retain stable official resolution without repository or patch overrides.';
    }
    if (! in_array($lane, ['minimum', 'current', 'moving-dev'], true)) {
        $errors[] = 'Unknown dependency lane.';
    }
    $packages = array_column(array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []), null, 'name');
    $runtime = array_column($installed['packages'] ?? [], null, 'name');
    foreach (['laravel/ai' => 'https://github.com/laravel/ai.git', 'laravel/framework' => 'https://github.com/laravel/framework.git'] as $name => $url) {
        $p = $packages[$name] ?? [];
        $version = $p['version'] ?? '';
        $ref = $p['source']['reference'] ?? '';
        if (($p['source']['type'] ?? null) !== 'git' || ($p['source']['url'] ?? null) !== $url || ! preg_match('/^[a-f0-9]{40}$/D', $ref)) {
            $errors[] = $name.' must resolve official source and a full commit.';
        }
        if (($runtime[$name]['version'] ?? null) !== $version || ($runtime[$name]['source']['reference'] ?? null) !== $ref || ($runtime[$name]['source']['url'] ?? null) !== $url) {
            $errors[] = $name.' installed package must match the verified lock.';
        }
        if ($lane === 'moving-dev') {
            if ($version !== ($name === 'laravel/ai' ? '0.x-dev' : '13.x-dev')) {
                $errors[] = $name.' did not resolve the exact compatible moving development branch.';
            }
        } elseif ($name === 'laravel/ai') {
            if (! preg_match('/^v?0\.11\.\d+$/D', $version) || version_compare(ltrim($version, 'v'), '0.11.2', '<')) {
                $errors[] = 'Stable AI must be >=0.11.2 and <0.12.0.';
            }
            if ($lane === 'minimum' && ($version !== 'v0.11.2' || $ref !== 'ee2c5162838d440c4e2e629ea93c8c87e838eaed')) {
                $errors[] = 'Minimum lane must execute the exact official AI v0.11.2 release.';
            }
        } elseif (! preg_match('/^v?13\.\d+\.\d+$/D', $version)) {
            $errors[] = 'Stable framework must be Laravel 13.';
        }
    }

    return $errors;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $lane = $argv[1] ?? '';
        $root = $argv[2] ?? getcwd();
        $manifestPath = $argv[3] ?? $root.'/composer.json';
        $read = fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $lock = $read($root.'/composer.lock');
        $errors = adoptionDependencyErrors($read($manifestPath), $lock, $read($root.'/vendor/composer/installed.json'), $lane);
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
            if (in_array($p['name'], ['laravel/ai', 'laravel/framework', 'orchestra/testbench'], true)) {
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
