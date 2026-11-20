<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each wait records the run's wait_generation it was created at, so a wait closer can
 * tell whether its transaction sees every wait created on the run. Waits created before
 * this migration keep NULL and are not counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_durable_waits', function (Blueprint $table): void {
            $table->bigInteger('creation_generation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('swarm_durable_waits', function (Blueprint $table): void {
            $table->dropColumn('creation_generation');
        });
    }
};
