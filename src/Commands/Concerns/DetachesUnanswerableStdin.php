<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Concerns;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stops a prompt blocking forever on a stdin that will never answer.
 *
 * A console command that prompts with no terminal behind it does not fail — it
 * hangs, which in CI or a test lane reads as a stuck runner rather than a
 * failure. Issue #449: a generator test hung past ten minutes and had to be
 * killed. The captured stack ran `Laravel\Prompts\select()` → the Prompts
 * fallback → Symfony's `QuestionHelper` → `TerminalInputHelper::waitForInput()`,
 * with 2266 of 2313 samples inside `stream_select()`.
 *
 * WHAT ACTUALLY BLOCKS is narrower than "stdin is not a terminal", and getting
 * that wrong is how the first attempt at this fix went astray. The busy-wait
 * is keyed on the *identity of the stream*, not on whether anyone can answer:
 *
 *     // QuestionHelper::ask()
 *     $inputStream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
 *     $inputStream ??= \STDIN;
 *
 *     // TerminalInputHelper::__construct()
 *     $this->isStdin = 'php://stdin' === stream_get_meta_data($inputStream)['uri'];
 *
 *     // TerminalInputHelper::waitForInput() — only ever reached when isStdin
 *     while (0 === @stream_select($r, $w, $w, 0, 100)) { $r = [$this->inputStream]; }
 *
 * So the hang requires the `php://stdin` resource specifically. Give the input
 * any other stream and the loop is unreachable: `QuestionHelper` reads the
 * empty stream, gets nothing, and returns the question's own declared default —
 * or aborts loudly where the argument is required.
 *
 * That is why this is an `initialize()` hook rather than a guard at each prompt.
 * Guarding the call sites means deciding *whether to ask*, which changes what
 * the command does; this changes only *what it reads from*, so every prompt
 * still runs and still yields the same answer it yielded before. Production
 * behaviour on a non-terminal run is unchanged — which matters, because
 * `laravel/prompts` already degraded safely there (`Prompt::prompt()` returns
 * `$this->default()` when `stream_isatty(STDIN)` is false) and an earlier
 * call-site guard silently flipped migrations from applied to skipped.
 *
 * Note the condition that arms the blocking fallback is
 * `windows_os() || $app->runningUnitTests()`, and `runningUnitTests()` is
 * `$app['env'] === 'testing'` — a shipped configuration value, not "PHPUnit is
 * running". An application deployed with `APP_ENV=testing` arms it in
 * production, so this cannot be treated as a test-only concern.
 *
 * KNOWN LIMITATION, deliberately accepted: a pipe that *would* have answered is
 * no longer read. `echo yes | php artisan swarm:audit:reconcile --dismiss=42`
 * declines instead of confirming. Nothing here can distinguish a pipe that will
 * deliver a line from one that never will — the read blocks either way — so the
 * choice is between losing piped answers and hanging indefinitely. Documented in
 * UPGRADING.md with `--force` as the replacement.
 *
 * @internal
 */
trait DetachesUnanswerableStdin
{
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        if ($input instanceof StreamableInputInterface && $this->stdinCannotAnswer($input)) {
            // Empty and readable: QuestionHelper reads EOF and falls through to
            // the question's declared default rather than waiting on a stream
            // that will never become readable.
            $stream = fopen('php://memory', 'r+');

            // If even a memory stream cannot be opened, leave the input as it
            // was. Worse to break the command outright than to leave it with the
            // behaviour it has today.
            if ($stream !== false) {
                $input->setStream($stream);
            }
        }

        parent::initialize($input, $output);
    }

    /**
     * Whether this input would read from the process's own stdin, with no
     * terminal behind it.
     *
     * An explicit stream set by a caller — `CommandTester::setInputs()`, a test
     * harness, an application driving the command itself — is left alone: it was
     * provided precisely so it would be read, and it is not `php://stdin`, so it
     * cannot reach the busy-wait anyway.
     */
    protected function stdinCannotAnswer(StreamableInputInterface $input): bool
    {
        if ($input->getStream() !== null) {
            return false;
        }

        if (! defined('STDIN')) {
            return false;
        }

        return ! @stream_isatty(STDIN);
    }
}
