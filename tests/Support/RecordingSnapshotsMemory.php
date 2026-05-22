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

    public function find(string $runId, int $stepIndex): ?MemorySnapshot
    {
        return null;
    }
}
