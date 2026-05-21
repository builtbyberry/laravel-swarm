<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Installer;

use PHPUnit\Framework\Assert;

/**
 * Fluent result object returned from {@see InstallerTestCase::runInstaller()}.
 *
 * Captures the exit code, console output, and a snapshot of the host-app
 * skeleton's filesystem state at the moment the installer finished, then
 * exposes assertions on top of those — including the idempotency double-run
 * helper ({@see twice()} + {@see assertSecondRunIsNoOp()}).
 *
 * Result objects are produced by the harness; do not construct directly.
 */
final class InstallerRunResult
{
    /**
     * @param  array<string, string>  $skeletonSnapshot  map of relative path => sha256 of file contents at run time
     */
    public function __construct(
        public readonly string $command,
        /** @var array<string, mixed> */
        public readonly array $arguments,
        public readonly int $exitCode,
        public readonly string $output,
        public readonly array $skeletonSnapshot,
        private readonly InstallerTestCase $testCase,
    ) {}

    /**
     * Assert the installer exited with the given status code.
     */
    public function assertExitCode(int $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->exitCode,
            "Installer [{$this->command}] expected exit code {$expected}, got {$this->exitCode}.\nOutput:\n{$this->output}",
        );

        return $this;
    }

    /**
     * Assert the installer exited successfully (exit code 0).
     */
    public function assertSucceeded(): self
    {
        return $this->assertExitCode(0);
    }

    /**
     * Assert the installer console output contains the given fragment.
     */
    public function assertOutputContains(string $needle): self
    {
        Assert::assertStringContainsString(
            $needle,
            $this->output,
            "Installer [{$this->command}] output did not contain expected fragment.",
        );

        return $this;
    }

    /**
     * Run the installer a second time with the same command and arguments.
     *
     * Returns a {@see DoubleRunResult} so the caller can chain
     * `->assertSecondRunIsNoOp()` and verify the installer is safe to re-run.
     */
    public function twice(): DoubleRunResult
    {
        $second = $this->testCase->runInstallerOnce($this->command, $this->arguments);

        return new DoubleRunResult($this, $second);
    }
}
