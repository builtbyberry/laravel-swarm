<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Durable;

use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchAdmission;

/**
 * Kills its own process at the moment a finished branch would reconcile — after the
 * branch's terminal write has committed. Used by the fan-out cap process test to
 * prove swarm:recover repairs a lost reconcile.
 */
class KilledAfterCommitBranchAdmission extends DurableBranchAdmission
{
    public function reconcileAfterCommit(string $runId): void
    {
        posix_kill((int) getmypid(), SIGKILL);
    }
}
