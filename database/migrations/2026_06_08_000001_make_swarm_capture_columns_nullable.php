<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow CaptureDecision::Skip to persist NULL on the run-history step columns.
 *
 * Before v0.12 every non-Full capture decision collapsed to REDACTED, so these
 * columns were always written with a string and declared NOT NULL. True Skip
 * omission writes NULL on the run-history evidence step I/O, which requires
 * swarm_run_steps.input/output to be nullable. (swarm_run_histories.output was
 * already nullable.)
 *
 * Scope note: the active-context store (swarm_contexts.input) is OPERATIONAL
 * runtime state — it is the only persisted source of the top-level input for
 * durable resume — and is governed by the capture policy's *evidence*
 * projection, never omitted operationally. It therefore stays NOT NULL.
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
    }

    /**
     * Re-imposes NOT NULL on the step I/O columns. If a custom CapturePolicy
     * returning Skip has already written NULL rows, set them to a sentinel or
     * delete those rows before rolling back — this down() will otherwise fail
     * the NOT NULL change. We deliberately do not backfill NULL -> '' here:
     * that would rewrite "deliberately omitted" evidence as an empty string.
     */
    public function down(): void
    {
        Schema::table('swarm_run_steps', function (Blueprint $table): void {
            $table->longText('input')->nullable(false)->change();
            $table->longText('output')->nullable(false)->change();
        });
    }
};
