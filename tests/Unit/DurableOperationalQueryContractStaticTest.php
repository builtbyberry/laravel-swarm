<?php

declare(strict_types=1);

it('forbids json-path sql predicates in durable query surfaces', function (): void {
    $packageRoot = dirname(__DIR__, 2);

    /**
     * Scope to paths where durable/list SQL and Pulse/recorder code live. Scanning all of
     * `src/` was unnecessarily brittle for unrelated package code; extend this list if a
     * new directory introduces operational queries against durable tables.
     *
     * @var list<string>
     */
    $scopedRelativeRoots = [
        'src/Persistence',
        'src/Commands',
        'src/Runners',
        'src/Pulse',
    ];

    /**
     * Relative paths (from package root) excluded from the scan. Empty by default.
     *
     * @var list<string>
     */
    $allowlistRelativeFiles = [
    ];

    $patterns = [
        'whereJson' => '/\bwhereJson[A-Za-z_]*/',
        'JSON_EXTRACT' => '/\bJSON_EXTRACT\b/i',
        'json_extract(' => '/\bjson_extract\s*\(/i',
    ];

    $violations = [];

    foreach ($scopedRelativeRoots as $relative) {
        $srcRoot = $packageRoot.'/'.$relative;

        if (! is_dir($srcRoot)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = str_replace('\\', '/', substr($path, strlen($packageRoot) + 1));

            if ($relativePath !== '' && in_array($relativePath, $allowlistRelativeFiles, true)) {
                continue;
            }

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
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});
