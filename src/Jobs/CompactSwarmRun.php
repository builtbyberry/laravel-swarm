<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Compaction\SwarmCompactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * @internal
 */
class CompactSwarmRun implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $runId,
    ) {}

    public function handle(SwarmCompactor $compactor): void
    {
        $compactor->compact($this->runId);
    }

    public function displayName(): string
    {
        return 'compact:'.$this->runId;
    }
}
