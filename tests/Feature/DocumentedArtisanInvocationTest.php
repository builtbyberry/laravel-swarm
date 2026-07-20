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
        // Canonical agent guidance per CLAUDE.md. Contributes no invocations
        // today (it mentions commands only in prose), so this is forward cover
        // for the day it does — not present-day coverage.
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

    // CHANGELOG.md is scanned, but only its UNRELEASED section (truncated in
    // extractPackageInvocations below). Released entries are historical record —
    // v0.22.0's still carries the bad invocation and rewriting shipped history
    // would be an erratum, not a silent edit. The unreleased section is written
    // fresh every release and IS a first-class instruction surface, so leaving
    // it unguarded would let a new entry ship the next bad invocation.
    // UPGRADING.md is scanned whole: it is living guidance, and v0.23.0 already
    // reversed a v0.5.0 note in place.
    //
    // That truncation is load-bearing: the v0.22.0 entry still quotes the bad
    // invocation this component fixed, and the option assertion does not filter
    // on `anchored`. So if the heading regex ever stops matching, the guard
    // fails on shipped history the team has decided never to edit — check the
    // CHANGELOG heading format before believing such a failure.
    $files[] = $root.'/CHANGELOG.md';

    return array_values(array_filter($files, static fn (string $p): bool => is_file($p)));
}

/**
 * @return array<int, array{command: string, options: array<int, string>, anchored: bool, file: string, line: int}>
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
        // An optional NAME argument may sit between the command and its flags —
        // `make:swarm:swarm YourSwarm --single`, `swarm:memory:inspect r-abc
        // --step=0`. Without this, ~40 documented option usages are invisible,
        // including the canonical one-agent form this component's own fix
        // prescribes. (This was added once, then silently dropped by a later
        // rework of the pattern block — hence the regression test below.)
        $argument = '(?:\s+(?:"[^"]*"|<[^>]+>|\{[^}]+\}|[A-Za-z0-9][\w.\/-]*))?';
        $options = $argument.'((?:\s+--[a-z0-9-]+(?:=[^\s`)]+)?)*)';

        // `swarm:` is an overloaded namespace — it also prefixes memory keys
        // (`swarm:step.{n}.output`), config values (`swarm:artifacts:`) and
        // Context keys (`swarm:actor`). An earlier version required the word
        // `artisan` before a `swarm:*` match to exclude those, but that left
        // every bare `swarm:health --flag` in prose unguarded — roughly 40% of
        // this repo's documented option coverage (54 of 136 option checks).
        // Callers now classify against the live command registry instead, which
        // excludes the non-command keys precisely (they are not registered)
        // while still reaching bare forms.
        //
        // Scope is deliberately THIS package's commands. An earlier rework
        // widened the second alternation to every `make:*`, which silently
        // pulled in `make:agent` (laravel/ai) plus `make:job` and `make:event`
        // (framework) — commands this file disclaims owning, and whose upstream
        // rename would fail our docs test for no fault of ours.
        //
        // Known blind spot: matching is line-scoped, so a hard-wrapped
        // `php artisan` / `swarm:trace <id>` split across two lines is read as
        // bare rather than anchored (docs/audit-evidence-contract.md wraps that
        // way). It is still option-checked; only the command-exists assertion
        // skips it.
        $pattern = '/(?<![:a-z0-9])((?:swarm:[a-z0-9-]+(?::[a-z0-9-]+)*)|make:(?:swarm(?::[a-z0-9-]+)?|memory-tool))(?![:.\w])'.$options.'/i';

        if (! preg_match_all($pattern, $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($matches as $match) {
            preg_match_all('/--([a-z0-9-]+)/i', $match[2][0] ?? '', $optionMatches);

            $found[] = [
                'command' => $match[1][0],
                'options' => $optionMatches[1] ?? [],
                // Anchored means the text literally said `artisan <cmd>`, so it
                // is unambiguously an invocation and MUST resolve. A bare token
                // might be a memory key, so an unknown one is ignored rather
                // than reported — its options are still checked when it names a
                // real command. Detected from the preceding text rather than a
                // second pattern, so the two cannot drift apart.
                'anchored' => (bool) preg_match('/\bartisan\s+$/i', substr($line, 0, $match[1][1])),
                'file' => str_replace(dirname(__DIR__, 2).'/', '', $path),
                'line' => $index + 1,
            ];
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

test('every scanned root still contributes invocations', function () {
    // A global floor cannot see ONE root disappearing: dropping `resources/`
    // moves the total by six and both floors still pass — yet that is the
    // directory the v0.22.0 defect lived in (resources/boost/guidelines).
    // Assert each root pulls its weight instead, so a rename or a dropped
    // entry in the source list fails loudly and names the root.
    $perRoot = [];

    foreach (documentedInvocationSources() as $path) {
        $relative = str_replace(dirname(__DIR__, 2).'/', '', $path);
        $root = str_contains($relative, '/') ? explode('/', $relative)[0] : $relative;
        $perRoot[$root] = ($perRoot[$root] ?? 0) + count(extractPackageInvocations($path));
    }

    // AGENTS.md is deliberately absent: it contributes nothing today and is
    // scanned as forward cover (see documentedInvocationSources).
    foreach (['docs', 'src', 'resources', 'stubs', 'README.md', 'UPGRADING.md'] as $root) {
        expect($perRoot[$root] ?? 0)->toBeGreaterThan(
            0,
            "The scanned root [{$root}] contributed no Artisan invocations. It has been renamed, emptied, or dropped from documentedInvocationSources() — the guard is now blind to it.",
        );
    }
});

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

            // A bare `swarm:*` token might be a memory key or config prefix,
            // so an unknown one is only an error when the text said `artisan`.
            // `make:*` carries no such ambiguity — nothing else in this repo
            // uses that prefix — and five of the eight sites carrying the
            // v0.22.0 defect wrote the bare command in prose, so bare make:*
            // is always checked.
            if (! $use['anchored'] && ! str_starts_with($use['command'], 'make:')) {
                continue;
            }

            $checked++;

            if (! in_array($use['command'], $registered, true)) {
                $offenders[] = sprintf('%s:%d — `%s` is not a registered command', $use['file'], $use['line'], $use['command']);
            }
        }
    }

    // A `> 0` floor does not detect coverage COLLAPSE: dropping directories
    // from the scan left a handful of invocations and still passed. This floor
    // sits well below today's real count — 200 anchored, non-exempt
    // invocations — because it exists to catch a broken scan (the same
    // mutation yields 32), not to police docs churn. Deliberately generous:
    // docs/generators.md alone carries 19, so a couple of pages consolidating
    // must not trip it. If this fails, check the scan before the number.
    // Offenders first: a floor trip must not mask genuine bad invocations
    // present in the same run behind a "lost coverage" message.
    expect($offenders)->toBe([], "Documented commands that do not exist:\n  ".implode("\n  ", $offenders));

    expect($checked)->toBeGreaterThan(140, "Only {$checked} invocations were scanned (~200 expected). Check whether the scan lost coverage — a directory rename, a regex change — before assuming the docs simply shrank.");
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

    expect($offenders)->toBe([], "Documented options that do not exist on the named command:\n  ".implode("\n  ", $offenders));

    expect($checked)->toBeGreaterThan(100, "Only {$checked} documented options were scanned (~136 expected). Check whether the scan lost coverage before assuming the docs simply have fewer options.");
});
