<?php

declare(strict_types=1);

it('forbids json-path sql predicates across package src for durable operational queries', function (): void {
    $srcRoot = dirname(__DIR__, 2).'/src';

    $patterns = [
        'whereJson' => '/\bwhereJson[A-Za-z_]*/',
        'JSON_EXTRACT' => '/\bJSON_EXTRACT\b/i',
        'json_extract(' => '/\bjson_extract\s*\(/i',
    ];

    $violations = [];

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($srcRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var \SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $contents = file_get_contents($path);

        if ($contents === false) {
            continue;
        }

        foreach ($patterns as $label => $regex) {
            if (preg_match_all($regex, $contents, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            $lines = explode("\n", $contents);

            foreach ($matches[0] as [, $byteOffset]) {
                $prefix = substr($contents, 0, (int) $byteOffset);
                $lineNumber = substr_count($prefix, "\n") + 1;
                $lineContent = $lines[$lineNumber - 1] ?? '';
                $violations[] = "{$path}:{$lineNumber}: matched [{$label}] in: ".trim($lineContent);
            }
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});
