<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Concerns;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stops a prompt blocking forever on a stdin that will never answer.
 *
 * This concern owns one local invariant: before a command can prompt, an input
 * with no explicit stream and no terminal receives an empty readable stream.
 * Explicit caller streams and terminal input remain untouched. Keeping the
 * behavior in `initialize()` covers inherited prompts as well as prompts in a
 * command's own handler.
 *
 * The upstream diagnosis and captured stack are recorded in GitHub issue #449.
 * The deliberately accepted piped-answer limitation and its `--force`
 * replacement are documented in UPGRADING.md.
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
     * Whether this concern needs to replace the implicit process input.
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
