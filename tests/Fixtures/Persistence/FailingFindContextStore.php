<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Persistence;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use RuntimeException;

/**
 * The database context store whose find() throws once, on the given call for the
 * given run (counting from 1), as a transient database error would.
 */
final class FailingFindContextStore extends DatabaseContextStore
{
    public ?string $failRunId = null;

    public int $failOnCall = 0;

    private int $calls = 0;

    public function find(string $runId): ?array
    {
        if ($runId === $this->failRunId && ++$this->calls === $this->failOnCall) {
            throw new RuntimeException("Context read for [{$runId}] failed.");
        }

        return parent::find($runId);
    }
}
