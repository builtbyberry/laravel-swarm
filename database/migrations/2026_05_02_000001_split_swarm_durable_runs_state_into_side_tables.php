<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $runsTable = 'swarm_durable_runs';
        $nodeStatesTable = 'swarm_durable_node_states';
        $runStateTable = 'swarm_durable_run_state';

        Schema::create($nodeStatesTable, function (Blueprint $table): void {
            $table->id();
            $table->string('run_id');
            $table->string('node_id');
            $table->json('state');
            $table->timestamps();

            $table->unique(['run_id', 'node_id']);
            $table->index('run_id');
        });

        Schema::create($runStateTable, function (Blueprint $table): void {
            $table->string('run_id')->primary();
            $table->json('route_plan')->nullable();
            $table->boolean('route_plan_projected')->default(false);
            $table->json('failure')->nullable();
            $table->json('retry_policy')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable($runsTable) && Schema::hasColumns($runsTable, ['node_states', 'route_plan', 'failure', 'retry_policy'])) {
            DB::table($runsTable)->orderBy('run_id')->chunk(100, function ($rows) use ($nodeStatesTable, $runStateTable): void {
                foreach ($rows as $run) {
                    $runId = (string) $run->run_id;
                    $now = now('UTC');

                    $nodeStatesRaw = $run->node_states ?? null;
                    if (is_string($nodeStatesRaw) && $nodeStatesRaw !== '') {
                        $decoded = json_decode($nodeStatesRaw, true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $nodeId => $state) {
                                if (! is_string($nodeId) || ! is_array($state)) {
                                    continue;
                                }
                                DB::table($nodeStatesTable)->updateOrInsert(
                                    ['run_id' => $runId, 'node_id' => $nodeId],
                                    [
                                        'state' => json_encode($state),
                                        'created_at' => $now,
                                        'updated_at' => $now,
                                    ],
                                );
                            }
                        }
                    }

                    $hasRunState = ($run->route_plan !== null && $run->route_plan !== '')
                        || ($run->failure !== null && $run->failure !== '')
                        || ($run->retry_policy !== null && $run->retry_policy !== '');

                    if ($hasRunState) {
                        DB::table($runStateTable)->updateOrInsert(
                            ['run_id' => $runId],
                            [
                                'route_plan' => $run->route_plan,
                                'route_plan_projected' => false,
                                'failure' => $run->failure,
                                'retry_policy' => $run->retry_policy,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ],
                        );
                    }
                }
            });
        }

        Schema::table($runsTable, function (Blueprint $table): void {
            $table->dropColumn(['route_plan', 'node_states', 'failure', 'retry_policy']);
        });
    }

    public function down(): void
    {
        $runsTable = 'swarm_durable_runs';
        $nodeStatesTable = 'swarm_durable_node_states';
        $runStateTable = 'swarm_durable_run_state';

        Schema::table($runsTable, function (Blueprint $table): void {
            $table->json('route_plan')->nullable();
            $table->json('node_states')->nullable();
            $table->json('failure')->nullable();
            $table->json('retry_policy')->nullable();
        });

        if (Schema::hasTable($nodeStatesTable)) {
            $runIds = DB::table($nodeStatesTable)->distinct()->pluck('run_id');
            foreach ($runIds as $runId) {
                $rows = DB::table($nodeStatesTable)->where('run_id', $runId)->get();
                $states = [];
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->state, true);
                    if (is_array($decoded)) {
                        $states[(string) $row->node_id] = $decoded;
                    }
                }
                if ($states !== []) {
                    DB::table($runsTable)->where('run_id', $runId)->update([
                        'node_states' => json_encode($states),
                    ]);
                }
            }
        }

        if (Schema::hasTable($runStateTable)) {
            foreach (DB::table($runStateTable)->cursor() as $row) {
                DB::table($runsTable)->where('run_id', $row->run_id)->update([
                    'route_plan' => $row->route_plan,
                    'failure' => $row->failure,
                    'retry_policy' => $row->retry_policy,
                ]);
            }
        }

        Schema::dropIfExists($nodeStatesTable);
        Schema::dropIfExists($runStateTable);
    }
};
