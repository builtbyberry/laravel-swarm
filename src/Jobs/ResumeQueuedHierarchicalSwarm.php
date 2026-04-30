<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResumeQueuedHierarchicalSwarm implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $runId,
    ) {}

    public function handle(QueuedHierarchicalCoordinator $coordinator): void
    {
        $coordinator->resumeAfterParallelJoin($this->runId);
    }

    public function displayName(): string
    {
        return $this->runId.':resume-queued-hierarchical';
    }
}
