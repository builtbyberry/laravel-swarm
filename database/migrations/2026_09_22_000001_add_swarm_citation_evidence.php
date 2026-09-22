<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string, string> */
    private function tables(): array
    {
        return [
            'history' => 'swarm_run_histories', 'history_steps' => 'swarm_run_steps',
            'durable_branches' => 'swarm_durable_branches',
            'durable_node_outputs' => 'swarm_durable_node_outputs',
            'stream_step_checkpoints' => 'swarm_stream_step_checkpoints',
        ];
    }

    public function up(): void
    {
        foreach ($this->tables() as $key => $default) {
            $name = (string) config('swarm.tables.'.$key, $default);
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'citation_evidence')) {
                Schema::table($name, fn (Blueprint $table) => $table->longText('citation_evidence')->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $key => $default) {
            $name = (string) config('swarm.tables.'.$key, $default);
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'citation_evidence')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('citation_evidence'));
            }
        }
    }
};
