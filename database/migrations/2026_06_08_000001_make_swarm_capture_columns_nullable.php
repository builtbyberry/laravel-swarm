<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow CaptureDecision::Skip to persist NULL where a field is omitted.
 *
 * Before v0.12 every non-Full capture decision collapsed to REDACTED, so these
 * columns were always written with a string and declared NOT NULL. True Skip
 * omission writes NULL instead, which requires these columns to be nullable.
 *
 * The change is additive and backward compatible: existing rows hold non-null
 * REDACTED/plain values and continue to read unchanged. The default
 * BooleanCapturePolicy never returns Skip, so apps on the swarm.capture.*
 * booleans never write NULL here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_run_steps', function (Blueprint $table): void {
            $table->longText('input')->nullable()->change();
            $table->longText('output')->nullable()->change();
        });

        Schema::table('swarm_contexts', function (Blueprint $table): void {
            $table->longText('input')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('swarm_run_steps', function (Blueprint $table): void {
            $table->longText('input')->nullable(false)->change();
            $table->longText('output')->nullable(false)->change();
        });

        Schema::table('swarm_contexts', function (Blueprint $table): void {
            $table->longText('input')->nullable(false)->change();
        });
    }
};
