<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * One condition of a queued-hierarchical run's stranded parallel-boundary shape
 * (#615) broken on an otherwise stranded, aged run — shared by the sqlite and the
 * real-DB rollout tests so both engines see the same labelled cases. Each closure
 * takes the run id.
 */
final class StrandedJoinShapeBreakers
{
    /**
     * Conditions on the durable row (and its waits and branches).
     *
     * @return array<string, Closure(string): void>
     */
    public static function durable(): array
    {
        $durable = static fn (array $values): Closure => static function (string $runId) use ($values): void {
            DB::table('swarm_durable_runs')->where('run_id', $runId)->update($values);
        };

        return [
            'another profile' => $durable(['coordination_profile' => 'step_durable']),
            'durable waiting' => $durable(['status' => 'waiting']),
            'durable running' => $durable(['status' => 'running']),
            'finished' => static fn (string $runId) => DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['finished_at' => now('UTC')]),
            'retry scheduled' => static fn (string $runId) => DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['next_retry_at' => now('UTC')->subMinute()]),
            'no current node' => $durable(['current_node_id' => null]),
            'current node without branches' => $durable(['current_node_id' => 'finish_node']),
            'live durable lease' => static fn (string $runId) => DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['leased_until' => now('UTC')->addMinutes(5), 'execution_token' => 'live']),
            'moved within the grace period' => static fn (string $runId) => DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['updated_at' => now('UTC')]),
            'open wait' => static fn (string $runId) => DB::table('swarm_durable_waits')->insert([
                'run_id' => $runId, 'name' => 'still-open', 'status' => 'waiting',
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]),
        ];
    }

    /**
     * Conditions on the history row.
     *
     * @return array<string, Closure(string): void>
     */
    public static function history(): array
    {
        $history = static fn (string $status): Closure => static function (string $runId) use ($status): void {
            DB::table('swarm_run_histories')->where('run_id', $runId)->update(['status' => $status]);
        };

        return [
            'history waiting' => $history('waiting'),
            'history running' => $history('running'),
        ];
    }

    /**
     * @return array<string, Closure(string): void>
     */
    public static function all(): array
    {
        return array_merge(self::durable(), self::history());
    }

    /**
     * A stray step that died holding the durable lease: expired, its token left behind.
     * Still the stranded shape.
     */
    public static function expireDeadLease(string $runId): void
    {
        DB::table('swarm_durable_runs')->where('run_id', $runId)->update([
            'leased_until' => now('UTC')->subMinute(),
            'execution_token' => 'dead-stray-step',
        ]);
    }
}
