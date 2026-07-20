<?php

declare(strict_types=1);

/**
 * Guards the shipped stubs against re-teaching the deprecated agent marker.
 *
 * v0.23.0 widened Swarm to accept any `Laravel\Ai\Contracts\Agent`, but the fix
 * initially missed the generator stubs and the shipped example swarms: they
 * still declared `@return array<int, \BuiltByBerry\LaravelSwarm\Contracts\Agent>`.
 * That drift is invisible to the rest of the suite, because it only bites in a
 * consumer's project — scaffold a swarm, drop a plain `laravel/ai` agent into
 * `agents()` (the exact thing this release blesses), and *their* PHPStan flags
 * it, with the trail leading back to a stub in this package.
 *
 * Asserted at the file level rather than through the generators. The example
 * swarms are never generated at all — the installer copies them — so a
 * generator-output test could not reach them, and they are where most of the
 * original drift lived. (Generator-driven assertions are perfectly possible
 * otherwise; `MakeMemoryToolCommandTest` does exactly that for the vector stub,
 * kernel dance and all.)
 *
 * Scans `.stub`, `.php` AND `.md` — the shipped example READMEs carry
 * copy-paste agent code, which is the same "teaches the wrong contract"
 * surface as the stubs themselves.
 */
function shippedStubFiles(): array
{
    $root = dirname(__DIR__, 2).'/stubs';
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['stub', 'php', 'md'], true)) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

function relativeToRepoRoot(string $path): string
{
    return str_replace(dirname(__DIR__, 2).'/', '', $path);
}

test('no shipped stub references the deprecated swarm Agent marker', function () {
    $offenders = [];

    foreach (shippedStubFiles() as $path) {
        $contents = file_get_contents($path);

        if ($contents !== false && str_contains($contents, 'BuiltByBerry\LaravelSwarm\Contracts\Agent')) {
            $offenders[] = relativeToRepoRoot($path);
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These shipped stubs still reference the deprecated agent marker:\n  %s\n".
        'Use Laravel\Ai\Contracts\Agent instead — scaffolded code must not teach the deprecated alias.',
        implode("\n  ", $offenders),
    ));
});

test('every stub declaring agents() types its return as the vendor Agent contract', function () {
    // Per-file, not a corpus-wide count. A count only fails on mass deletion:
    // add an example swarm that forgets the annotation and a floor still
    // passes, which is precisely the regression this file exists to catch.
    $checked = [];
    $offenders = [];

    foreach (shippedStubFiles() as $path) {
        $contents = file_get_contents($path);

        if ($contents === false || ! str_contains($contents, 'function agents()')) {
            continue;
        }

        $checked[] = relativeToRepoRoot($path);

        // Match on the @return lines that mention an Agent at all, so the
        // assertion survives a reformat (`list<>` vs `array<int, >`, aliased
        // imports) instead of silently not matching.
        $agentReturns = array_values(array_filter(
            preg_split('/\R/', $contents) ?: [],
            static fn (string $line): bool => str_contains($line, '@return') && str_contains($line, 'Agent'),
        ));

        $namesVendorContract = $agentReturns !== [] && array_reduce(
            $agentReturns,
            static fn (bool $carry, string $line): bool => $carry && str_contains($line, 'Laravel\Ai\Contracts\Agent'),
            true,
        );

        if (! $namesVendorContract) {
            $offenders[] = relativeToRepoRoot($path);
        }
    }

    // Sanity: if the corpus ever scans to nothing, the assertion below would
    // pass vacuously. Fail loudly instead.
    expect($checked)->not->toBeEmpty('No stub declaring agents() was found — the scan is broken, not clean.');

    expect($offenders)->toBe([], sprintf(
        "These stubs declare agents() without typing the return as the vendor contract:\n  %s",
        implode("\n  ", $offenders),
    ));
});
