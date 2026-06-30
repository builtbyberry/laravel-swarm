<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-swarm durable per-node streaming opt-in (#310). Resolved from the
        // #[DurableStreaming] attribute and pinned here at run-start so every resume
        // reads the run's original decision rather than live config. Defaults to
        // false so existing runs (and any run of a swarm without the attribute)
        // never stream — fail-safe off.
        Schema::table('swarm_durable_runs', function (Blueprint $table): void {
            $table->boolean('durable_streaming')->default(false)->after('execution_mode');
        });
    }

    public function down(): void
    {
        Schema::table('swarm_durable_runs', function (Blueprint $table): void {
            $table->dropColumn('durable_streaming');
        });
    }
};
