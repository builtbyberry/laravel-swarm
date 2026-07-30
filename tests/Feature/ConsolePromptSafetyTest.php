<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Commands\Concerns\DetectsInteractiveConsole;
use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmSwarmCommand;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Guards every prompting command against blocking forever on a stdin that
 * cannot answer.
 *
 * `$input->isInteractive()` is not sufficient on its own. Symfony's `ArrayInput`
 * is interactive by default, so `Artisan::call()` can leave it true while STDIN
 * is not a terminal. Laravel Prompts then takes its fallback path — Symfony's
 * `QuestionHelper` — which ends in `TerminalInputHelper::waitForInput()` and
 * busy-waits on a stream that will never become readable. The command does not
 * fail; it hangs, which reads as a stuck runner rather than a failure (#449, a
 * generator test that hung past ten minutes).
 *
 * WHAT EACH TEST HERE IS WORTH, stated plainly so the next reader does not
 * assume more:
 *
 * - The static scan at the bottom is the REGRESSION PIN. Reintroduce
 *   `$this->input->isInteractive()` in any command and it fails, naming the
 *   file and line. Verified by reverting a command and watching it fail.
 * - The two guard tests assert the new signal directly. They are bounded and
 *   deterministic, but they test the fix rather than the defect: the trait is
 *   the fix, so they cannot fail "without" it.
 * - The command-level tests assert `make:swarm:swarm` completes for every
 *   topology including the prompting path. They do NOT reproduce the hang, and
 *   they were verified to pass with the guard removed. Constructing the command
 *   directly bypasses `ConfiguresPrompts`, which is what installs the blocking
 *   Prompts fallback in the first place. They are completion coverage, not
 *   proof of the fix — do not read a green run here as "the hang is tested".
 *
 * The end-to-end hang is environment-dependent: it reproduces only when the
 * runner's stdin leaves the input marked interactive, which happened roughly
 * once in eight runs. It was diagnosed by catching a hung process and sampling
 * it, not by a test, and the fix is a structural guard rather than a patch to
 * one code path.
 */
test('the console prompt guard refuses to prompt when stdin is not a terminal', function () {
    $command = new class extends Command
    {
        use DetectsInteractiveConsole;

        protected $signature = 'swarm-test:prompt-guard';

        public function probe(ArrayInput $input): bool
        {
            $this->input = $input;

            return $this->consoleCanPrompt();
        }
    };

    // The exact condition that hangs: the input claims to be interactive while
    // the process has no terminal to answer with. Under a test runner stdin is
    // never a TTY, so the guard must refuse regardless of what the input says.
    $interactive = new ArrayInput([]);
    $interactive->setInteractive(true);

    expect($command->probe($interactive))->toBeFalse();

    $nonInteractive = new ArrayInput([]);
    $nonInteractive->setInteractive(false);

    expect($command->probe($nonInteractive))->toBeFalse();
});

test('the console prompt guard still prompts when the question path is mocked', function () {
    $command = new class extends Command
    {
        use DetectsInteractiveConsole;

        protected $signature = 'swarm-test:mocked-questions';

        public function probe(ArrayInput $input, ?object $output): bool
        {
            $this->input = $input;

            if ($output !== null) {
                $this->output = $output;
            }

            return $this->consoleCanPrompt();
        }
    };

    $input = new ArrayInput([]);
    $input->setInteractive(true);

    // Laravel's expectsQuestion/expectsChoice bind a mocked OutputStyle that
    // answers from a queue without reading stdin, so a prompt cannot block.
    // Refusing here would make every interactive path in this package
    // untestable — guarding on the terminal alone broke four existing tests.
    $mockedOutput = Mockery::mock(OutputStyle::class);

    expect($command->probe($input, $mockedOutput))->toBeTrue();
});

test('the console prompt guard reports whether stdin is a terminal', function () {
    $command = new class extends Command
    {
        use DetectsInteractiveConsole;

        protected $signature = 'swarm-test:stdin-probe';

        public function probe(): bool
        {
            return $this->consoleStdinIsTerminal();
        }
    };

    // Under any test runner stdin is a pipe or /dev/null, never a terminal.
    // If this ever reports true the guard has stopped measuring what it thinks
    // it measures, and every prompting command is one flake from hanging again.
    expect($command->probe())->toBeFalse();
});

test('make:swarm:swarm completes for every topology, including the path that would prompt', function (string $topology, string $class, string $expected) {
    $path = app_path("Ai/Swarms/{$class}.php");
    File::ensureDirectoryExists(dirname($path));

    // Force the input interactive — the state that preceded the #449 hang.
    // NOTE this does not reproduce the hang: constructing the command directly
    // skips ConfiguresPrompts, so the blocking Prompts fallback is never
    // installed. This asserts completion per topology, nothing stronger.
    $input = new ArrayInput(array_filter([
        'name' => $class,
        '--topology' => $topology !== '' ? $topology : null,
    ]));
    $input->setInteractive(true);

    $command = new MakeSwarmSwarmCommand(app(Filesystem::class));
    $command->setLaravel(app());

    $status = $command->run($input, new BufferedOutput);

    expect($status)->toBe(0)
        ->and(File::exists($path))->toBeTrue()
        ->and(File::get($path))->toContain($expected);
})->with([
    'no topology (the prompting path)' => ['', 'PromptGuardDefaultSwarm', 'TopologyEnum::Sequential'],
    'sequential' => ['sequential', 'PromptGuardSequentialSwarm', 'TopologyEnum::Sequential'],
    'parallel' => ['parallel', 'PromptGuardParallelSwarm', 'TopologyEnum::Parallel'],
    'hierarchical' => ['hierarchical', 'PromptGuardHierarchicalSwarm', 'TopologyEnum::Hierarchical'],
    'static-hierarchical' => ['static-hierarchical', 'PromptGuardStaticHierSwarm', 'TopologyEnum::StaticHierarchical'],
]);

test('every command that prompts uses the shared guard rather than the input alone', function () {
    $root = dirname(__DIR__, 2).'/src/Commands';
    $offenders = [];
    $scanned = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        // The guard itself is the one place allowed to read the raw signal.
        if (str_contains($contents, 'trait DetectsInteractiveConsole')) {
            continue;
        }

        $scanned++;

        foreach (explode("\n", $contents) as $index => $line) {
            if (str_contains($line, '$this->input->isInteractive()')) {
                $offenders[] = sprintf(
                    '%s:%d — reads the input flag directly; use consoleCanPrompt() so a non-terminal stdin cannot hang the command',
                    'src/Commands/'.str_replace($root.'/', '', $file->getPathname()),
                    $index + 1
                );
            }
        }
    }

    expect($scanned)->toBeGreaterThan(20, 'The command scan found almost nothing — the check is probably broken.');

    expect($offenders)->toBe([], "Commands reading isInteractive() directly:\n".implode("\n", $offenders));
});

afterEach(function () {
    foreach (glob(app_path('Ai/Swarms/PromptGuard*.php')) ?: [] as $file) {
        File::delete($file);
    }
});
