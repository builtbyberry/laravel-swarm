<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;

/**
 * Recording test double that captures every snapshot() and appendToolCall()
 * call so runner tests can assert the runner wires snapshots correctly
 * without needing a database connection.
 */
final class RecordingSnapshotsMemory implements SnapshotsMemory
{
    /** @var array<int, array{run_id: string, step_index: int}> */
    public array $snapshotCalls = [];

    /** @var array<int, array{run_id: string, step_index: int, tool_call: array<string, mixed>}> */
    public array $toolCallAppends = [];

    /** @var array<int, array{run_id: string, step_index: int}> */
    public array $toolCallResets = [];

    /** @var array<string, MemorySnapshot> Stub canned `find()` results keyed by `{runId}:{stepIndex}`. */
    public array $preloaded = [];

    public function snapshot(string $runId, int $stepIndex): MemorySnapshot
    {
        $this->snapshotCalls[] = ['run_id' => $runId, 'step_index' => $stepIndex];

        return new MemorySnapshot($runId, $stepIndex, [], []);
    }

    public function appendToolCall(MemorySnapshot $snapshot, array $toolCall): MemorySnapshot
    {
        $this->toolCallAppends[] = [
            'run_id' => $snapshot->runId,
            'step_index' => $snapshot->stepIndex,
            'tool_call' => $toolCall,
        ];

        return $snapshot->withToolCall($toolCall);
    }

    public function resetToolCalls(MemorySnapshot $snapshot): MemorySnapshot
    {
        $this->toolCallResets[] = [
            'run_id' => $snapshot->runId,
            'step_index' => $snapshot->stepIndex,
        ];

        return $snapshot->withClearedToolCalls();
    }

    public function find(string $runId, int $stepIndex): ?MemorySnapshot
    {
        return $this->preloaded[$runId.':'.$stepIndex] ?? null;
    }

    public function allForRun(string $runId): array
    {
        $matches = [];

        foreach ($this->preloaded as $key => $snapshot) {
            if (str_starts_with($key, $runId.':')) {
                $matches[] = $snapshot;
            }
        }

        usort(
            $matches,
            static fn (MemorySnapshot $a, MemorySnapshot $b): int => $a->stepIndex <=> $b->stepIndex,
        );

        return $matches;
    }

    public function preload(MemorySnapshot $snapshot): void
    {
        $this->preloaded[$snapshot->runId.':'.$snapshot->stepIndex] = $snapshot;
    }
}
