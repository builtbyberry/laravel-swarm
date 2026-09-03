<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\Concerns\DetachesUnanswerableStdin;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Run a real command process with stdin held open but never written.
 *
 * The process boundary and held-open pipe are the regression fixture: an
 * in-process assertion or an input closed at EOF passes without exercising the
 * hang. GitHub issue #449 owns the upstream diagnosis and captured stack.
 *
 * @return array{timedOut: bool, seconds: float, output: string, spawned: bool}
 */
function promptSafetyRunWithDeadStdin(string $command, int $timeoutSeconds = 20): array
{
    $root = dirname(__DIR__, 2);

    $process = proc_open(
        'php vendor/bin/testbench '.$command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );

    if (! is_resource($process)) {
        return ['timedOut' => false, 'seconds' => 0.0, 'output' => '', 'spawned' => false];
    }

    // $pipes[0] is deliberately left open and never written to. Closing it would
    // deliver EOF and defeat the entire point of the test.
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $startedAt = microtime(true);
    $timedOut = false;

    while (true) {
        $status = proc_get_status($process);
        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);

        if (! $status['running']) {
            break;
        }

        if (microtime(true) - $startedAt > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process, 9);

            break;
        }

        usleep(100_000);
    }

    $output .= (string) stream_get_contents($pipes[1]);

    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    proc_close($process);

    return [
        'timedOut' => $timedOut,
        'seconds' => (float) (microtime(true) - $startedAt),
        'output' => $output,
        'spawned' => true,
    ];
}

test('a prompting command exits on an unanswerable stdin instead of hanging', function (string $command, string $expected) {
    $result = promptSafetyRunWithDeadStdin($command.' --env=testing');

    expect($result['spawned'])->toBeTrue('Could not spawn the testbench subprocess.');

    expect($result['timedOut'])->toBeFalse(sprintf(
        "`%s` blocked on an unanswerable stdin for %.1fs instead of exiting.\nOutput:\n%s",
        $command,
        $result['seconds'],
        $result['output'] === '' ? '(none — it blocked before writing anything)' : $result['output']
    ));

    // A POSITIVE requirement, deliberately, because "it did not hang" is not the
    // same claim as "it ran". Anything that makes the subprocess die early also
    // makes it finish fast, and this pin would bank the green: an unregistered
    // service provider, a moved binary, a renamed command. An earlier version
    // banned two known-bad output strings instead and still passed vacuously
    // when `vendor/bin/testbench` could not be opened at all — PHP prints
    // "Could not open input file", exits at once, and matches neither ban.
    // Asserting what a completed run actually prints closes the whole class
    // rather than the two members of it someone thought of.
    expect($result['output'])->toContain($expected);
})->with([
    // The exact #449 shape: a required argument omitted, so the framework's
    // inherited prompt-for-missing-input fires before handle() ever runs. This
    // is the row that hangs against the pre-fix tree; it aborts rather than
    // generating, because the name it needed could never be answered.
    'make:swarm:swarm, missing required argument' => ['make:swarm:swarm', 'Aborted.'],
    'make:swarm:swarm' => ['make:swarm:swarm PromptSafetyProbeSwarm', 'Swarm [app/Ai/Swarms/PromptSafetyProbeSwarm.php] created successfully.'],
    'make:swarm:agent' => ['make:swarm:agent PromptSafetyProbeAgent', 'Agent [app/Ai/Agents/PromptSafetyProbeAgent.php] created successfully.'],
    'make:memory-tool' => ['make:memory-tool PromptSafetyProbeTool', 'Tool [app/Ai/Tools/PromptSafetyProbeTool.php] created successfully.'],
])->group('subprocess');

test('every command that can prompt detaches an unanswerable stdin', function () {
    $root = dirname(__DIR__, 2).'/src/Commands';
    $offenders = [];
    $scanned = 0;

    /** @var array<string, string> $sources */
    $sources = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    foreach ($sources as $path => $contents) {
        if (str_contains($contents, 'trait DetachesUnanswerableStdin')) {
            continue;
        }

        // Anything that can reach a question: a laravel/prompts helper imported
        // or called fully-qualified, one of Laravel's own InteractsWithIO
        // prompts (directly or via the components layer), or the
        // prompt-for-missing-input behaviour inherited from GeneratorCommand.
        //
        // This is a literal match against how prompts are written today, and
        // that is a real limit: a helper reached through an alias, a variable
        // callable, or a base class outside src/Commands would be invisible
        // here. It errs toward flagging — a docblock mentioning a prompt is
        // enough to require the trait — because a false positive costs one line
        // and a false negative ships another hang.
        $canPrompt = str_contains($contents, 'use function Laravel\Prompts\\')
            || str_contains($contents, '\\Laravel\\Prompts\\')
            || preg_match('/\$this->(confirm|ask|askWithCompletion|choice|secret|anticipate)\(/', $contents) === 1
            || preg_match('/\$this->components->(confirm|ask|askWithCompletion|choice|secret|anticipate)\(/', $contents) === 1
            || str_contains($contents, 'extends GeneratorCommand');

        if (! $canPrompt) {
            continue;
        }

        $scanned++;

        // Applying the trait is necessary but not sufficient. A class-declared
        // method beats a trait's, so a command that grows its own initialize()
        // silently stops running the hook while every other check here stays
        // green — the trait is still imported, the scan still passes, and the
        // command hangs again. Chaining to parent is what keeps it wired.
        if (str_contains($contents, 'function initialize(')
            && ! str_contains($contents, 'parent::initialize(')) {
            $offenders[] = sprintf(
                '%s — declares initialize() without calling parent::initialize(), which silently disables the stdin hook',
                'src/Commands/'.str_replace($root.'/', '', $path)
            );

            continue;
        }

        if (str_contains($contents, 'use DetachesUnanswerableStdin;')) {
            continue;
        }

        // A subclass inherits the hook from its parent; resolve one level, which
        // is as deep as this package's command hierarchy goes.
        if (preg_match('/class \w+ extends (\w+)/', $contents, $m) === 1) {
            $inherited = false;

            foreach ($sources as $candidate => $candidateContents) {
                if (str_ends_with($candidate, '/'.$m[1].'.php')
                    && str_contains($candidateContents, 'use DetachesUnanswerableStdin;')) {
                    $inherited = true;

                    break;
                }
            }

            if ($inherited) {
                continue;
            }
        }

        $offenders[] = sprintf(
            '%s — can prompt but neither applies DetachesUnanswerableStdin nor inherits it',
            'src/Commands/'.str_replace($root.'/', '', $path)
        );
    }

    // The `extends GeneratorCommand` clause is what the previous attempt lacked.
    // Its scan banned a single literal string, so a command inheriting its
    // prompt from a framework trait was invisible — which is how make:swarm:agent
    // and make:memory-tool shipped still hanging while the pin stayed green.
    expect($scanned)->toBeGreaterThan(5, 'The prompting-command scan found almost nothing — the check is probably broken.');

    expect($offenders)->toBe([], "Commands that can prompt but do not detach stdin:\n".implode("\n", $offenders));
});

test('the concern leaves a caller-supplied input stream alone', function () {
    $command = new class extends Command
    {
        use DetachesUnanswerableStdin;

        protected $signature = 'swarm-test:stream-probe';

        public function probe(StreamableInputInterface $input): bool
        {
            return $this->stdinCannotAnswer($input);
        }
    };

    // A caller that set its own stream — CommandTester::setInputs(), a harness,
    // an application driving the command — provided it precisely so it would be
    // read, and it is not php://stdin, so it can never reach the busy-wait.
    $withStream = new ArrayInput([]);
    $withStream->setStream(fopen('php://memory', 'r+'));

    expect($command->probe($withStream))->toBeFalse();

    // No stream of its own: detach exactly when this process has no terminal.
    // Asserted against the live value rather than a hard-coded expectation, so
    // this passes under a pty as well as a pipe — the previous attempt's tests
    // hard-asserted "not a terminal" and failed on a maintainer's machine.
    $withoutStream = new ArrayInput([]);

    expect($command->probe($withoutStream))->toBe(! @stream_isatty(STDIN));
});

/**
 * Remove any probe classes a previous run generated into the testbench app.
 */
function promptSafetyClearProbeClasses(): void
{
    $app = dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/app';

    foreach (['Ai/Swarms', 'Ai/Agents', 'Ai/Tools'] as $dir) {
        foreach (glob($app.'/'.$dir.'/PromptSafetyProbe*.php') ?: [] as $file) {
            @unlink($file);
        }
    }
}

// BEFORE as well as after. The success assertions expect "created successfully",
// and a generator whose target already exists reports "already exists" instead —
// so a run killed between generating and cleaning up would leave this suite
// failing on state rather than on behaviour, and the obvious reading of that
// failure ("the hang is back") would be wrong. Cleaning up front makes each run
// independent of how the last one ended.
beforeEach(function () {
    promptSafetyClearProbeClasses();
});

afterEach(function () {
    promptSafetyClearProbeClasses();
});
