<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Facade;

/**
 * Validates the package's own documentation REFERENCES against the shipped code.
 *
 * Nothing checked this before. `src/` carries ~600 `{@see}` references plus file
 * paths, Artisan command names and stated requirements quoted in prose, and
 * PHPStan has no phpdoc reference rules. v0.23.0 showed the cost: `AGENTS.md`
 * pointed every agent at a review skill that is not installed, listed 8 of 24
 * Artisan commands, and carried three stale dependency pins — all mechanically
 * checkable, none checked.
 *
 * SCOPE IS REFERENTIAL ONLY. This suite answers "does the thing this sentence
 * points at exist?" — nothing more. It CANNOT catch a semantic claim that was
 * false when written: "X does not support Y", "both modes re-resolve from the
 * container", "shields you from upstream churn". Every one of those was a real
 * v0.23.0 defect and none of them would fail here. A green run means the
 * references resolve, NOT that the documentation is true. Do not read it as
 * broader coverage than that, and do not widen these checks into semantic
 * territory — that is a separate control (the docblock cross-component rule).
 *
 * Each check is deliberately NARROW. An over-eager reference check produces
 * false positives, gets suppressed, and then reports green while testing
 * nothing — which is the failure this package has already shipped once, in a
 * nightly lane that passed 30 consecutive times while masking its own
 * resolution failure. Precision is the point: every case below that is skipped
 * is skipped with a stated reason, and every failure names its offender
 * individually rather than asserting a count. A corpus-total assertion is
 * insufficient — a `>= 11` floor once passed in this repo while every
 * generator stub underneath it was broken.
 */
function docRefRepoRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return array<int, string>
 */
function docRefSourceFiles(): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(docRefRepoRoot().'/src', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

/**
 * Markdown the package ships and is responsible for.
 *
 * CHANGELOG.md and UPGRADING.md are deliberately excluded from the requirement
 * check below — both are historical by nature, and a changelog entry naming the
 * version something changed in is correct precisely because it does not track
 * the current manifest. They ARE included in the path check, where a dead link
 * is a defect regardless of the file's age.
 *
 * @return array<int, string>
 */
function docRefMarkdownFiles(): array
{
    $found = glob(docRefRepoRoot().'/docs/*.md') ?: [];

    foreach (['README.md', 'AGENTS.md', 'CONTRIBUTING.md', 'UPGRADING.md', 'CHANGELOG.md'] as $name) {
        $path = docRefRepoRoot().'/'.$name;

        if (is_file($path)) {
            $found[] = $path;
        }
    }

    sort($found);

    return $found;
}

function docRefRelative(string $path): string
{
    return str_replace(docRefRepoRoot().'/', '', $path);
}

/**
 * Namespace, use-alias map and enclosing type for one source file.
 *
 * Regex rather than an AST parser: this package ships no parser dependency, and
 * the shapes involved (one namespace, plain `use` statements, one top-level
 * type per PSR-4 file) are unambiguous enough that a parser would be cost
 * without benefit. If a file ever declares two top-level types the enclosing
 * type resolves to the first, which can only cause a false NEGATIVE — a missed
 * bad reference, never a spurious failure.
 *
 * @return array{namespace: string, uses: array<string, string>, enclosing: string|null}
 */
function docRefFileContext(string $contents): array
{
    $namespace = '';
    if (preg_match('/^namespace\s+([^;]+);/m', $contents, $m) === 1) {
        $namespace = trim($m[1]);
    }

    $uses = [];
    if (preg_match_all('/^use\s+(?!function\s|const\s)([^;]+);/m', $contents, $matches) > 0) {
        foreach ($matches[1] as $clause) {
            $clause = trim($clause);

            if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $clause, $aliased) === 1) {
                $uses[$aliased[2]] = ltrim(trim($aliased[1]), '\\');

                continue;
            }

            $segments = explode('\\', $clause);
            $uses[end($segments)] = ltrim($clause, '\\');
        }
    }

    $enclosing = null;
    if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $contents, $m) === 1) {
        $enclosing = $namespace !== '' ? $namespace.'\\'.$m[1] : $m[1];
    }

    return ['namespace' => $namespace, 'uses' => $uses, 'enclosing' => $enclosing];
}

/**
 * Short class name => FQCN, for every type this package declares.
 *
 * A `{@see Foo}` naming a package type that the file does not import does not
 * resolve under PHP's name-resolution rules, but it is unambiguous to a reader
 * and points at something real. Flagging those would bury the handful of
 * references that point at NOTHING under dozens that merely lack an import —
 * and a check whose signal is mostly noise gets suppressed. Resolving them here
 * keeps this lane pointed at dead references.
 *
 * @return array<string, string>
 */
function docRefPackageClassMap(): array
{
    static $map = null;

    if ($map !== null) {
        return $map;
    }

    $map = [];

    foreach (docRefSourceFiles() as $file) {
        $contents = (string) file_get_contents($file);
        $context = docRefFileContext($contents);

        if ($context['enclosing'] === null) {
            continue;
        }

        $segments = explode('\\', $context['enclosing']);
        $map[end($segments)] = $context['enclosing'];
    }

    return $map;
}

/**
 * A facade resolves its members through `__callStatic` to an instance this
 * check cannot reach, so member existence is unverifiable rather than false.
 */
function docRefIsFacade(string $fqcn): bool
{
    try {
        return is_subclass_of($fqcn, Facade::class);
    } catch (Throwable) {
        return false;
    }
}

function docRefTypeExists(string $fqcn): bool
{
    $fqcn = ltrim($fqcn, '\\');

    if ($fqcn === '') {
        return false;
    }

    try {
        return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);
    } catch (Throwable) {
        // A class whose own dependencies cannot autoload is a different defect
        // from a dead reference; do not report it as one.
        return true;
    }
}

/**
 * Resolve a docblock type name to the FQCN it means in this file.
 *
 * @param  array<string, string>  $uses
 */
function docRefResolveType(string $name, string $namespace, array $uses): string
{
    if (str_starts_with($name, '\\')) {
        return ltrim($name, '\\');
    }

    $head = explode('\\', $name)[0];

    if (isset($uses[$head])) {
        return $uses[$head].substr($name, strlen($head));
    }

    $sameNamespace = $namespace !== '' ? $namespace.'\\'.$name : $name;

    if (docRefTypeExists($sameNamespace)) {
        return $sameNamespace;
    }

    return $name;
}

function docRefMemberExists(string $fqcn, string $member): bool
{
    $member = rtrim($member, '()');

    if (str_starts_with($member, '$')) {
        return property_exists($fqcn, substr($member, 1));
    }

    if (method_exists($fqcn, $member) || property_exists($fqcn, $member)) {
        return true;
    }

    try {
        return (new ReflectionClass($fqcn))->hasConstant($member);
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param  array{namespace: string, uses: array<string, string>, enclosing: string|null}  $context
 * @return string|null a human-readable reason the reference does not resolve, or null when it does
 */
function docRefUnresolvedReason(string $reference, array $context): ?string
{
    // `{@see $foo}` names a property or a parameter. Distinguishing the two
    // needs the enclosing signature, so a parameter reference would be reported
    // as a dead property. Skipped deliberately rather than guessed at.
    if (str_starts_with($reference, '$')) {
        return null;
    }

    // Bare URLs in `{@see}` are links, not symbols.
    if (str_starts_with($reference, 'http://') || str_starts_with($reference, 'https://')) {
        return null;
    }

    if (str_contains($reference, '::')) {
        [$left, $right] = explode('::', $reference, 2);

        $owner = in_array($left, ['self', 'static', '$this'], true)
            ? $context['enclosing']
            : docRefResolveOwner($left, $context);

        if ($owner === null) {
            return 'refers to `'.$left.'` but the file declares no enclosing type';
        }

        if (! docRefTypeExists($owner)) {
            // A NAMESPACED external type that is not installed here — an
            // optional or suggested dependency — cannot be verified either way.
            // A bare name that resolved to nothing is not that: it names no
            // type in this package, no imported type, and no global type, so it
            // is a dead reference and must be reported. Without the
            // `str_contains` guard this arm silently swallows every unresolvable
            // short name, which is how an earlier revision of this check passed
            // against a deliberately injected `{@see NoSuchClass::nope()}`.
            return docRefIsPackageType($owner) || ! str_contains($owner, '\\')
                ? 'type `'.$owner.'` does not exist'
                : null;
        }

        if (docRefIsFacade($owner)) {
            return null;
        }

        return docRefMemberExists($owner, $right)
            ? null
            : 'type `'.$owner.'` has no member `'.rtrim($right, '()').'`';
    }

    // `{@see method()}` — a member of the type this docblock lives in, or a
    // global PHP function such as `{@see strtr()}`.
    if (str_ends_with($reference, '()')) {
        $bare = rtrim($reference, '()');

        if (function_exists($bare)) {
            return null;
        }

        $owner = $context['enclosing'];

        if ($owner === null) {
            return 'refers to `'.$reference.'` but the file declares no enclosing type';
        }

        return docRefMemberExists($owner, $reference)
            ? null
            : 'type `'.$owner.'` has no member `'.$bare.'`';
    }

    // A bare name is usually a type, but may be a constant or a parenless
    // member of the enclosing type. Accept any of the three.
    $resolved = docRefResolveOwner($reference, $context);

    if ($resolved !== null && docRefTypeExists($resolved)) {
        return null;
    }

    if ($context['enclosing'] !== null && docRefMemberExists($context['enclosing'], $reference)) {
        return null;
    }

    if ($resolved !== null && ! docRefIsPackageType($resolved) && str_contains($resolved, '\\')) {
        // Resolved to an external type that is not installed — see above.
        return null;
    }

    return 'does not resolve to a type (tried `'.$resolved.'`), a constant, or a member of the enclosing type';
}

function docRefIsPackageType(string $fqcn): bool
{
    return str_starts_with(ltrim($fqcn, '\\'), 'BuiltByBerry\\LaravelSwarm\\');
}

/**
 * Resolve a docblock type name, falling back to this package's own class map
 * for an unimported short name.
 *
 * @param  array{namespace: string, uses: array<string, string>, enclosing: string|null}  $context
 */
function docRefResolveOwner(string $name, array $context): ?string
{
    $resolved = docRefResolveType($name, $context['namespace'], $context['uses']);

    if (docRefTypeExists($resolved)) {
        return $resolved;
    }

    if (! str_contains($name, '\\') && ! str_starts_with($name, '\\')) {
        $map = docRefPackageClassMap();

        if (isset($map[$name])) {
            return $map[$name];
        }
    }

    return $resolved;
}

/**
 * Every Artisan command this package registers.
 *
 * Read from BOTH `$signature` and `$name`: the generators declare `$name`, and
 * a scan of only one property silently halves the real set — the shape of the
 * v0.23.0 defect where AGENTS.md listed 8 of 24 commands.
 *
 * @return array<int, string>
 */
function docRefPackageCommands(): array
{
    $names = [];

    foreach (docRefSourceFiles() as $file) {
        $contents = (string) file_get_contents($file);

        if (preg_match('/\$signature\s*=\s*\'([^\'\s]+)/', $contents, $m) === 1) {
            $names[] = $m[1];
        }

        if (preg_match('/\$name\s*=\s*\'([^\']+)\'/', $contents, $m) === 1) {
            $names[] = $m[1];
        }
    }

    $names = array_values(array_unique(array_filter($names)));
    sort($names);

    return $names;
}

test('every {@see} reference in src resolves to a real symbol', function () {
    $scanned = 0;
    $offenders = [];

    foreach (docRefSourceFiles() as $file) {
        $contents = (string) file_get_contents($file);

        if (! str_contains($contents, '{@see')) {
            continue;
        }

        $context = docRefFileContext($contents);
        $lines = explode("\n", $contents);

        foreach ($lines as $index => $line) {
            if (preg_match_all('/\{@see\s+([^}]+)\}/', $line, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $raw) {
                // `{@see Thing some trailing prose}` — the reference is the
                // first token; the rest is the human description.
                $reference = trim(explode(' ', trim($raw))[0]);

                if ($reference === '') {
                    continue;
                }

                $scanned++;
                $reason = docRefUnresolvedReason($reference, $context);

                if ($reason !== null) {
                    $offenders[] = sprintf(
                        '%s:%d  {@see %s} — %s',
                        docRefRelative($file),
                        $index + 1,
                        $reference,
                        $reason
                    );
                }
            }
        }
    }

    // An empty scan must fail rather than pass vacuously: a refactor that
    // changed the reference syntax would otherwise turn this into a green lane
    // that checks nothing.
    expect($scanned)->toBeGreaterThan(400, 'The {@see} scan found almost nothing — the check is probably broken, not the docs.');

    expect($offenders)->toBe([], "Unresolvable {@see} references:\n".implode("\n", $offenders));
});

test('every repository path referenced in source comments and docs exists', function () {
    $scanned = 0;
    $offenders = [];

    // `config/` is deliberately absent. This package ships exactly one config
    // file (config/swarm.php); every other `config/*.php` named in prose —
    // queue.php, horizon.php, octane.php — is the CONSUMING application's, and
    // asserting those exist here would fail on files this repo never owned.
    $pathPattern = '#(?<![\\w/.-])((?:src|tests|docs|database|resources|stubs|examples|bin|tools|\\.github)/[A-Za-z0-9_./*-]+\\.(?:php|md|json|ya?ml|neon|xml|stub))#';

    foreach (docRefSourceFiles() as $file) {
        $lines = explode("\n", (string) file_get_contents($file));

        foreach ($lines as $index => $line) {
            // Source paths only appear in comments; a match in code is a string
            // literal the runtime already proves.
            if (! str_contains($line, '*') && ! str_contains($line, '//')) {
                continue;
            }

            if (preg_match_all($pathPattern, $line, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $path) {
                // Globs name a shape, not a file.
                if (str_contains($path, '*')) {
                    continue;
                }

                $scanned++;

                if (! file_exists(docRefRepoRoot().'/'.$path)) {
                    $offenders[] = sprintf('%s:%d  %s', docRefRelative($file), $index + 1, $path);
                }
            }
        }
    }

    // Relative markdown links, which is where a dead pointer actually reaches a
    // reader. External links and pure anchors are out of scope.
    foreach (docRefMarkdownFiles() as $file) {
        $lines = explode("\n", (string) file_get_contents($file));

        foreach ($lines as $index => $line) {
            if (preg_match_all('/\]\(([^)\s]+)\)/', $line, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $target) {
                if (str_starts_with($target, 'http') || str_starts_with($target, '#') || str_starts_with($target, 'mailto:')) {
                    continue;
                }

                $path = explode('#', $target)[0];

                if ($path === '') {
                    continue;
                }

                $scanned++;
                $resolved = str_starts_with($path, '/')
                    ? docRefRepoRoot().$path
                    : dirname($file).'/'.$path;

                if (! file_exists($resolved)) {
                    $offenders[] = sprintf('%s:%d  %s', docRefRelative($file), $index + 1, $target);
                }
            }
        }
    }

    expect($scanned)->toBeGreaterThan(50, 'The path scan found almost nothing — the check is probably broken.');

    expect($offenders)->toBe([], "Referenced paths that do not exist:\n".implode("\n", $offenders));
});

test('every documented artisan command this package owns is registered', function () {
    $registered = docRefPackageCommands();

    // Commands registered under a dynamic prefix: the concrete name is built at
    // runtime from a user's own swarm/example class, so only the prefix is
    // knowable here.
    $dynamicPrefixes = ['swarm:run:', 'swarm:example:'];

    // Commands that belong to a COMPANION package rather than core, each with
    // the package that owns it. Documenting them here is correct — a reader who
    // installs the companion runs exactly these — but core cannot register
    // them, so an existence check against src/ would be wrong.
    $companionCommands = [
        'swarm:install:pulse' => 'builtbyberry/laravel-swarm-pulse',
    ];

    $scanned = 0;
    $offenders = [];

    $files = array_merge(docRefSourceFiles(), docRefMarkdownFiles());

    foreach ($files as $file) {
        $lines = explode("\n", (string) file_get_contents($file));

        foreach ($lines as $index => $line) {
            if (preg_match_all('/php artisan ([a-z][a-z0-9:._-]*)/', $line, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $command) {
                // Only commands in this package's own namespaces. Framework and
                // third-party commands (migrate, queue:work, vendor:publish,
                // boost:install) are not ours to verify, and asserting on them
                // would make this lane fail on someone else's rename.
                $owned = str_starts_with($command, 'swarm:')
                    || str_starts_with($command, 'make:swarm')
                    || $command === 'make:memory-tool';

                if (! $owned) {
                    continue;
                }

                $scanned++;

                if (in_array($command, $registered, true) || isset($companionCommands[$command])) {
                    continue;
                }

                foreach ($dynamicPrefixes as $prefix) {
                    if (str_starts_with($command, $prefix)) {
                        continue 2;
                    }
                }

                $offenders[] = sprintf('%s:%d  php artisan %s', docRefRelative($file), $index + 1, $command);
            }
        }
    }

    expect(count($registered))->toBeGreaterThanOrEqual(24, 'Command discovery found fewer commands than this package is known to ship; the scan, not the docs, is probably wrong.');
    expect($scanned)->toBeGreaterThan(30, 'The artisan-command scan found almost nothing — the check is probably broken.');

    expect($offenders)->toBe([], "Documented commands this package does not register:\n".implode("\n", $offenders));
});

test('the stated requirements match composer.json', function () {
    /** @var array{require: array<string, string>} $manifest */
    $manifest = json_decode((string) file_get_contents(docRefRepoRoot().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $require = $manifest['require'];

    // ONLY the "## Requirements" block. Prose elsewhere legitimately names old
    // versions — "the MCP tools added in `laravel/ai` 0.8" is a true statement
    // about history, not a stale pin, and flagging it is exactly the kind of
    // false positive that gets a check switched off.
    $readme = (string) file_get_contents(docRefRepoRoot().'/README.md');

    expect($readme)->toContain('## Requirements');

    $section = explode('## Requirements', $readme, 2)[1];
    $section = explode("\n## ", $section, 2)[0];

    $scanned = 0;
    $offenders = [];

    foreach (explode("\n", $section) as $line) {
        if (! str_starts_with(trim($line), '- ')) {
            continue;
        }

        if (preg_match('/`?(php|laravel\/ai|illuminate\/[a-z*-]+)`?[^`]*\*\*\^?([0-9]+\.[0-9]+)\*\*/i', $line, $m) !== 1) {
            continue;
        }

        $package = strtolower($m[1]);
        $stated = $m[2];

        $constraint = $require[$package] ?? ($package === 'illuminate/*' ? null : null);

        // The README names `illuminate/*` collectively; compare against any one
        // of the illuminate requirements, which the manifest keeps in lockstep.
        if ($constraint === null && str_starts_with($package, 'illuminate/')) {
            $constraint = $require['illuminate/support'] ?? null;
        }

        if ($constraint === null) {
            $offenders[] = sprintf('README.md  states a requirement on `%s`, which composer.json does not require', $package);

            continue;
        }

        $scanned++;

        if (! str_contains($constraint, $stated)) {
            $offenders[] = sprintf(
                'README.md  states `%s` %s but composer.json requires %s',
                $package,
                $stated,
                $constraint
            );
        }
    }

    expect($scanned)->toBeGreaterThan(2, 'The requirements scan found almost nothing — the check is probably broken.');

    expect($offenders)->toBe([], "Stated requirements that disagree with composer.json:\n".implode("\n", $offenders));
});
