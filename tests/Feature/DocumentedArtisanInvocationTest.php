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
        // Canonical agent guidance per CLAUDE.md, and the same class of file as
        // resources/boost/guidelines/core.blade.php where the defect lived.
        $root.'/AGENTS.md',
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

    // CHANGELOG.md is scanned, but only its UNRELEASED section (see
    // changelogUnreleasedSection). Released entries are historical record —
    // v0.22.0's still carries the bad invocation and rewriting shipped history
    // would be an erratum, not a silent edit. The unreleased section is written
    // fresh every release and IS a first-class instruction surface, so leaving
    // it unguarded would let a new entry ship the next bad invocation.
    // UPGRADING.md is scanned whole: it is living guidance, and v0.23.0 already
    // reversed a v0.5.0 note in place.
    $files[] = $root.'/CHANGELOG.md';

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

    // For CHANGELOG.md, keep only the text above the first DATED (released)
    // heading — everything below it is shipped history, not instruction.
    if (basename($path) === 'CHANGELOG.md'
        && preg_match('/^##\s+\S+\s+-\s+\d{4}-\d{2}-\d{2}/m', $contents, $m, PREG_OFFSET_CAPTURE)) {
        $contents = substr($contents, 0, $m[0][1]);
    }

    $found = [];

    foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
        // Package commands only: swarm:*, make:swarm*, make:memory-tool.
        // Capture any --options that follow on the same line.
        $options = '((?:\s+--[a-z0-9-]+(?:=[^\s`)]+)?)*)';

        // `swarm:` is an overloaded namespace — it also prefixes memory keys
        // (`swarm:step.{n}.output`), config values (`swarm:artifacts:`) and
        // Context keys (`swarm:actor`). An earlier version required the word
        // `artisan` before a `swarm:*` match to exclude those, but that left
        // every bare `swarm:health --flag` in prose unguarded — over half of
        // this repo's documented option usage. Callers now classify against the
        // live command registry instead, which excludes the non-command keys
        // precisely (they are not registered) while still reaching bare forms.
        $patterns = [
            '/\bartisan\s+((?:swarm|make):[a-z0-9-]+(?::[a-z0-9-]+)*)(?![:.\w])'.$options.'/i',
            '/(?<![:a-z0-9])((?:swarm|make):[a-z0-9-]+(?::[a-z0-9-]+)*)(?![:.\w])'.$options.'/i',
        ];

        foreach ($patterns as $anchored => $pattern) {
            if (! preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                preg_match_all('/--([a-z0-9-]+)/i', $match[2] ?? '', $optionMatches);

                $key = $match[1].'@'.($index + 1).'@'.$match[2];

                $found[$key] = [
                    'command' => $match[1],
                    'options' => $optionMatches[1] ?? [],
                    // Anchored means the text literally said `artisan <cmd>`, so
                    // it is unambiguously an invocation and MUST resolve. An
                    // unanchored token might be a memory key, so an unknown one
                    // is ignored rather than reported.
                    //
                    // Both patterns match the same anchored text, and the
                    // unanchored one runs second — so OR the flag rather than
                    // overwrite it, or every entry collapses to unanchored and
                    // the command assertion silently checks nothing.
                    'anchored' => ($found[$key]['anchored'] ?? false) || $anchored === 0,
                    'file' => str_replace(dirname(__DIR__, 2).'/', '', $path),
                    'line' => $index + 1,
                ];
            }
        }
    }

    return array_values($found);
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
        // Listed by EXACT name, not by prefix: a prefix pattern would also
        // exempt a typo like `swarm:example:blog-pipelien`, shipping a
        // copy-pasteable command that errors for the reader.
        'swarm:example:blog-pipeline',
        'swarm:example:research-fanout',
        'swarm:example:approval-workflow',
        'swarm:example:support-triage',
        'swarm:example:contact-extraction',
        'swarm:example:conversation-memory',
        'swarm:example:streaming',
        // Generated into the consuming app by `make:swarm:blueprint`, which
        // mints a `swarm:run:<name>` runner alongside the blueprint
        // (MakeSwarmBlueprintCommand::327) — a different owner from the
        // examples installer above.
        'swarm:run:support-triage',
        // Ships with the builtbyberry/laravel-swarm-pulse companion package,
        // extracted from core in v0.17.1.
        'swarm:install:pulse',
    ];
}

test('every documented package Artisan command exists', function () {
    $registered = array_keys(Artisan::all());
    $exempt = invocationsNotOwnedByThisPackage();
    $offenders = [];
    $checked = 0;

    foreach (documentedInvocationSources() as $path) {
        foreach (extractPackageInvocations($path) as $use) {
            if (in_array($use['command'], $exempt, true)) {
                continue;
            }

            // Only an `artisan <cmd>` form is unambiguously an invocation. A
            // bare token might be a memory key or config prefix, so an unknown
            // one is not an error — its OPTIONS are still checked by the
            // sibling test when it does resolve to a real command.
            if (! $use['anchored']) {
                continue;
            }

            $checked++;

            if (! in_array($use['command'], $registered, true)) {
                $offenders[] = sprintf('%s:%d — `%s` is not a registered command', $use['file'], $use['line'], $use['command']);
            }
        }
    }

    // A `> 0` floor does not detect coverage COLLAPSE: dropping directories
    // from the scan left a handful of invocations and still passed. These
    // floors sit below today's real counts (236 anchored invocations) with
    // headroom for docs churn, but well above what a broken scan yields.
    expect($checked)->toBeGreaterThan(180, "Only {$checked} anchored invocations were scanned (expected ~236). The scan has lost coverage — a directory rename or regex change, not a clean tree.");

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

    expect($checked)->toBeGreaterThan(100, "Only {$checked} documented options were scanned (expected ~136). The scan has lost coverage rather than the docs having fewer options.");

    expect($offenders)->toBe([], "Documented options that do not exist on the named command:\n  ".implode("\n  ", $offenders));
});
