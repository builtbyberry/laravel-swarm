<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

class DurableRunDetail
{
    /**
     * @param  array<string, mixed>|null  $run
     * @param  array<string, mixed>|null  $history
     * @param  array<string, mixed>  $labels
     * @param  array<string, mixed>  $details
     * @param  array<int, array<string, mixed>>  $waits
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<int, array<string, mixed>>  $progress
     * @param  array<int, array<string, mixed>>  $children
     * @param  array<int, array<string, mixed>>  $branches
     */
    public function __construct(
        public readonly string $runId,
        public readonly ?array $run,
        public readonly ?array $history = null,
        public readonly array $labels = [],
        public readonly array $details = [],
        public readonly array $waits = [],
        public readonly array $signals = [],
        public readonly array $progress = [],
        public readonly array $children = [],
        public readonly array $branches = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'run' => $this->run,
            'history' => $this->history,
            'labels' => $this->labels,
            'details' => $this->details,
            'waits' => $this->waits,
            'signals' => $this->signals,
            'progress' => $this->progress,
            'children' => $this->children,
            'branches' => $this->branches,
        ];
    }
}
