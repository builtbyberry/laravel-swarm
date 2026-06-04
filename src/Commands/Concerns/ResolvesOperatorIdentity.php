<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Concerns;

/**
 * Best-effort identity of the human operator behind an artisan invocation, for
 * the audit trail's "who did this" record.
 *
 * Artisan has no authenticated application user, so this is necessarily a
 * best-effort chain rather than an authoritative identity:
 *
 *   1. `SUDO_USER` — the invoking user before a `sudo` privilege drop (the
 *      closest thing to "who really ran this").
 *   2. `USER` / `LOGNAME` / `USERNAME` — the login-shell user for an
 *      interactive run.
 *   3. The POSIX effective-uid owner — the process owner when the environment
 *      carries no shell-user hint.
 *   4. `get_current_user()` — the script owner, as a last resort.
 *
 * Under a queue worker or php-fpm the result is the daemon's user, not the
 * person who triggered the work — callers that need an authoritative human
 * identity should require an explicit `--requested-by`/`--reason` and record
 * that verbatim. See `docs/compliance-audit.md`.
 *
 * @internal
 */
trait ResolvesOperatorIdentity
{
    protected function resolveRequestedBy(): string
    {
        foreach (['SUDO_USER', 'USER', 'LOGNAME', 'USERNAME'] as $key) {
            $value = $_SERVER[$key] ?? getenv($key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());

            if (is_array($info) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        $user = get_current_user();

        return $user !== '' ? $user : 'unknown';
    }
}
