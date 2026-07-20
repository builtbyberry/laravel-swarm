<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

readonly class DurableRunDetail
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
     * @param  array<int, array<string, mixed>>  $hierarchicalNodeOutputs
     */
    public function __construct(
        public string $runId,
        public ?array $run,
        public ?array $history = null,
        public array $labels = [],
        public array $details = [],
        public array $waits = [],
        public array $signals = [],
        public array $progress = [],
        public array $children = [],
        public array $branches = [],
        public array $hierarchicalNodeOutputs = [],
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
            'hierarchical_node_outputs' => $this->hierarchicalNodeOutputs,
        ];
    }
}
