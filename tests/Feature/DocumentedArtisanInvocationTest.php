<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * Every package Artisan invocation this repo documents must actually exist.
 *
 * v0.22.0 shipped `php artisan make:swarm:swarm --single` across eight files —
 * `src/Support/PendingRun.php`, the execution-modes and cookbook docs, the
 * Boost guidelines and skill, and UPGRADING.md. That option does not exist:
 * `--single` lives on the deprecated `make:swarm` alias, where it scaffolds an
 * *agent* rather than the swarm class every one of those sites was pointing at.
 * Wrong command, and the command that does carry the flag produces the wrong
 * artifact.
 *
 * It was introduced in 730cbb4 while resolving v0.22.0 readiness findings —
 * remediation for one review, written under the pressure of another fix, in a
 * twelve-finding commit, and never run. Nothing in the suite could catch it,
 * because nothing asserted that documented invocations resolve.
 *
 * This test inspects the command REGISTRY; it never executes a command. That is
 * deliberate on two counts: executing generators in a test is slow, and
 * `make:swarm:swarm` can block indefinitely under `Artisan::call` without a TTY
 * (see issue #449). Reading definitions has neither problem.
 *
 * Scope is this package's own commands (`swarm:*`, `make:swarm*`,
 * `make:memory-tool`). Framework and third-party commands are not ours to
 * assert.
 */
function documentedInvocationSources(): array
{
    $root = dirname(__DIR__, 2);

    $files = [
        $root.'/README.md',
        $root.'/UPGRADING.md',
    ];

    foreach (['docs', 'src', 'resources', 'stubs'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'md', 'stub'], true)) {
                $files[] = $file->getPathname();
            }
        }
    }

    // CHANGELOG.md is deliberately excluded: released entries are historical
    // record, not instructions. v0.22.0's entry still carries the bad
    // invocation and correcting it would be rewriting shipped history — the
    // house call is to leave it (an erratum, if ever, not a silent edit).
    // UPGRADING.md IS scanned: it is living guidance, and v0.23.0 already
    // reversed a v0.5.0 note in place.
    return array_values(array_filter($files, static fn (string $p): bool => is_file($p)));
}

/**
 * @return array<int, array{command: string, options: array<int, string>, file: string, line: int}>
 */
function extractPackageInvocations(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        return [];
    }

    $found = [];

    foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
        // Package commands only: swarm:*, make:swarm*, make:memory-tool.
        // Capture any --options that follow on the same line.
        $options = '((?:\s+--[a-z0-9-]+(?:=[^\s`)]+)?)*)';

        // `swarm:` is an overloaded namespace — it prefixes memory keys
        // (`swarm:step.{n}.output`), config values (`swarm:artifacts:`) and
        // Context keys (`swarm:actor`) as well as commands. So a `swarm:*`
        // match only counts when it directly follows `artisan`.
        //
        // `make:swarm*` / `make:memory-tool` need no such anchor: nothing else
        // in this codebase uses that prefix, and requiring `artisan` would have
        // missed five of the eight sites carrying the v0.22.0 defect, which
        // wrote the bare command inside prose and code comments.
        $patterns = [
            '/\bartisan\s+(swarm:[a-z0-9-]+(?::[a-z0-9-]+)*)(?![:.\w])'.$options.'/i',
            '/(?<![:a-z0-9])(make:swarm(?::[a-z0-9-]+)?|make:memory-tool)(?![:.\w])'.$options.'/i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                preg_match_all('/--([a-z0-9-]+)/i', $match[2] ?? '', $optionMatches);

                $found[] = [
                    'command' => $match[1],
                    'options' => $optionMatches[1] ?? [],
                    'file' => str_replace(dirname(__DIR__, 2).'/', '', $path),
                    'line' => $index + 1,
                ];
            }
        }
    }

    return $found;
}

/**
 * Commands that are correctly absent from THIS package's registry.
 *
 * Each entry needs a reason. This must not become a place to silence real
 * findings — if something lands here without a justification, that is the
 * defect, not the failing assertion.
 */
function invocationsNotOwnedByThisPackage(): array
{
    return [
        // Scaffolded into the CONSUMING app by `swarm:install:examples`, so
        // they only ever exist in an application's registry, never in ours.
        '/^swarm:example:/',
        '/^swarm:run:/',
        // Ships with the builtbyberry/laravel-swarm-pulse companion package,
        // extracted from core in v0.17.1.
        '/^swarm:install:pulse$/',
    ];
}

test('every documented package Artisan command exists', function () {
    $registered = array_keys(Artisan::all());
    $exempt = invocationsNotOwnedByThisPackage();
    $offenders = [];
    $checked = 0;

    foreach (documentedInvocationSources() as $path) {
        foreach (extractPackageInvocations($path) as $use) {
            foreach ($exempt as $pattern) {
                if (preg_match($pattern, $use['command'])) {
                    continue 2;
                }
            }

            $checked++;

            if (! in_array($use['command'], $registered, true)) {
                $offenders[] = sprintf('%s:%d — `%s` is not a registered command', $use['file'], $use['line'], $use['command']);
            }
        }
    }

    expect($checked)->toBeGreaterThan(0, 'No package Artisan invocations were found — the scan is broken, not clean.');

    expect($offenders)->toBe([], "Documented commands that do not exist:\n  ".implode("\n  ", $offenders));
});

test('every option documented against a package Artisan command is declared on it', function () {
    // The v0.22.0 defect in one assertion: `make:swarm:swarm --single` names a
    // real command and a real flag that belong to *different* commands.
    $commands = Artisan::all();
    $offenders = [];
    $checked = 0;

    foreach (documentedInvocationSources() as $path) {
        foreach (extractPackageInvocations($path) as $use) {
            if (! isset($commands[$use['command']])) {
                continue; // reported by the sibling test
            }

            $command = $commands[$use['command']];

            // Merge the application definition so global options (--no-interaction,
            // --quiet, --env, …) resolve. Symfony keeps those on the Application
            // and merges lazily at run time, so an unmerged command definition
            // knows only its own options and would false-positive on every global.
            $command->mergeApplicationDefinition();

            $definition = $command->getDefinition();

            foreach ($use['options'] as $option) {
                $checked++;

                if (! $definition->hasOption($option)) {
                    $offenders[] = sprintf(
                        '%s:%d — `%s` has no `--%s` option',
                        $use['file'],
                        $use['line'],
                        $use['command'],
                        $option,
                    );
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(0, 'No documented options were found — the scan is broken, not clean.');

    expect($offenders)->toBe([], "Documented options that do not exist on the named command:\n  ".implode("\n  ", $offenders));
});
