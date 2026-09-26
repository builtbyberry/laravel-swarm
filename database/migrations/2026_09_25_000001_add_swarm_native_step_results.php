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
            'history_steps' => 'swarm_run_steps',
            'durable_branches' => 'swarm_durable_branches',
            'durable_node_outputs' => 'swarm_durable_node_outputs',
            'stream_step_checkpoints' => 'swarm_stream_step_checkpoints',
        ];
    }

    public function up(): void
    {
        foreach ($this->tables() as $key => $default) {
            $name = (string) config('swarm.tables.'.$key, $default);
            if (! Schema::hasTable($name)) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (! Schema::hasColumn($name, 'native_result_status')) {
                    $table->string('native_result_status', 32)->nullable();
                }
                if (! Schema::hasColumn($name, 'native_result')) {
                    $table->longText('native_result')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $key => $default) {
            $name = (string) config('swarm.tables.'.$key, $default);
            if (! Schema::hasTable($name)) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $columns = array_values(array_filter(
                    ['native_result_status', 'native_result'],
                    static fn (string $column): bool => Schema::hasColumn($name, $column),
                ));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
