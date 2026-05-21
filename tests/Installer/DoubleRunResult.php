<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Installer;

use PHPUnit\Framework\Assert;

/**
 * Pair of {@see InstallerRunResult}s captured from a `runInstaller(...)->twice()` chain.
 *
 * Used to assert installer idempotency: the second run must succeed and the
 * host-app skeleton on disk must be byte-identical to the snapshot captured
 * after the first run.
 */
final class DoubleRunResult
{
    public function __construct(
        public readonly InstallerRunResult $first,
        public readonly InstallerRunResult $second,
    ) {}

    /**
     * Assert the second run was a no-op:
     *  - exited 0
     *  - did not change any file in the skeleton vs the first run's snapshot
     *  - did not create or delete any file
     */
    public function assertSecondRunIsNoOp(): self
    {
        Assert::assertSame(
            0,
            $this->second->exitCode,
            "Second run of installer [{$this->second->command}] expected exit code 0, got {$this->second->exitCode}.\nOutput:\n{$this->second->output}",
        );

        $before = $this->first->skeletonSnapshot;
        $after = $this->second->skeletonSnapshot;

        $created = array_diff_key($after, $before);
        $deleted = array_diff_key($before, $after);
        $modified = [];

        foreach ($before as $relative => $hash) {
            if (array_key_exists($relative, $after) && $after[$relative] !== $hash) {
                $modified[] = $relative;
            }
        }

        Assert::assertSame(
            [],
            array_keys($created),
            'Second installer run was not a no-op — these files were created: '.implode(', ', array_keys($created)),
        );

        Assert::assertSame(
            [],
            array_keys($deleted),
            'Second installer run was not a no-op — these files were deleted: '.implode(', ', array_keys($deleted)),
        );

        Assert::assertSame(
            [],
            $modified,
            'Second installer run was not a no-op — these files changed contents: '.implode(', ', $modified),
        );

        return $this;
    }
}
