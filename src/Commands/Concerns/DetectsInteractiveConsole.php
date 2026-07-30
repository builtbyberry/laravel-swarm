<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Concerns;

/**
 * Whether this command may ask the operator a question and expect an answer.
 *
 * `$this->input->isInteractive()` alone is NOT a sufficient guard, and trusting
 * it hangs the process indefinitely.
 *
 * Symfony's `ArrayInput` is interactive by default, so `Artisan::call()` can
 * leave the input marked interactive while STDIN is not a terminal. Laravel
 * Prompts then correctly detects the missing TTY and takes its FALLBACK path —
 * but that fallback is Symfony's `QuestionHelper`, which ends in
 * `TerminalInputHelper::waitForInput()`:
 *
 *     while (0 === @stream_select($r, $w, $w, 0, 100)) { $r = [$this->inputStream]; }
 *
 * an unbounded busy-wait on a stream that will never become readable. The
 * command does not fail — it blocks forever, which in a test lane or a CI job
 * reads as a stuck runner rather than a failure. That is issue #449, where a
 * generator test hung past ten minutes and had to be killed; the captured stack
 * ran `MakeSwarmSwarmCommand::resolveTopology()` → `Laravel\Prompts\select()` →
 * the Prompts fallback → `QuestionHelper` → that loop, with
 * `stream_isatty(STDIN)` reporting false throughout.
 *
 * It reproduced intermittently because the outcome depends on the STDIN the
 * test runner happened to inherit, which is exactly why the single-signal guard
 * looked correct for months.
 *
 * So: prompt only when the input says interactive AND stdin is a real terminal
 * that could actually answer — the same signal Laravel Prompts uses to decide
 * it cannot render. Where this returns false, the caller must fall back to a
 * documented default rather than asking.
 */
trait DetectsInteractiveConsole
{
    /**
     * True when a prompt can be both rendered and answered.
     */
    protected function consoleCanPrompt(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        if ($this->consoleStdinIsTerminal()) {
            return true;
        }

        return $this->consoleQuestionsAreMocked();
    }

    /**
     * Whether the question path is mocked and will answer without touching stdin.
     *
     * Laravel's `expectsQuestion()` / `expectsChoice()` / `expectsConfirmation()`
     * bind a mocked `OutputStyle` and answer `askQuestion()` from a queue, so no
     * read ever reaches stdin and a prompt cannot block. Refusing to prompt there
     * would make every interactive path in this package untestable — and it did:
     * guarding on the terminal alone broke four existing tests that legitimately
     * drive prompts through that API.
     *
     * Duck-typed rather than checking for `Mockery\MockInterface`, so nothing in
     * `src/` depends on a dev-only package. This is deliberately test-aware
     * production code: the alternative is either a hang in production or an
     * untestable prompt, and this is the smaller cost of the two.
     */
    protected function consoleQuestionsAreMocked(): bool
    {
        // `Command::$output` is declared non-nullable but is genuinely
        // uninitialized until `Command::run()` assigns it, so `isset()` is the
        // correct guard even though the signature says it cannot be unset.
        // @phpstan-ignore isset.property
        return isset($this->output) && method_exists($this->output, 'shouldReceive');
    }

    /**
     * Whether STDIN is a terminal.
     *
     * Deliberately un-cached: a static cache would leak the first answer across
     * every command in a long-lived process (Octane, a queue worker, or a test
     * suite that varies its input), and `stream_isatty()` is a cheap syscall.
     */
    protected function consoleStdinIsTerminal(): bool
    {
        if (defined('STDIN')) {
            return (bool) @stream_isatty(STDIN);
        }

        // Some SAPIs do not define the STDIN constant. Open our own handle and
        // close it again — never close STDIN itself.
        $stdin = @fopen('php://stdin', 'r');

        if ($stdin === false) {
            return false;
        }

        try {
            return (bool) @stream_isatty($stdin);
        } finally {
            @fclose($stdin);
        }
    }
}
