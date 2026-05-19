<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox for audit evidence that failed to emit through the
 * bound SwarmAuditSink.
 *
 * Routed here when the SinkFailureHandler returns SinkFailureDecision::Queue
 * (transient: retry through swarm:relay --type=audit) or ::DeadLetter
 * (terminal: persist for investigation, no retry).
 *
 * Drain flow (swarm:relay --type=audit):
 *   1. Claim rows atomically via FOR UPDATE SKIP LOCKED, setting reserved_at.
 *   2. Re-attempt sink emit for each claimed row.
 *   3. Delete the row on successful re-emission.
 *   4. On failure, increment attempts. After max_attempts the row's status
 *      moves to 'dead_letter' and stops being re-claimed.
 *
 * Unlike the durable outbox, audit records have no parent run cascade — they
 * may outlive the run that produced them.
 *
 * Retention: dead-letter rows are preserved indefinitely by default so that
 * regulated callers do not silently erase compliance evidence before
 * reconciliation. Operators can opt into automatic pruning by setting
 * swarm.audit.outbox.dead_letter_retention_days to a positive integer; when
 * set, swarm:prune deletes dead-letter rows whose last_attempted_at is older
 * than the configured window. Pending and reserved rows are never pruned by
 * this policy — staleness and retry are the relay's responsibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_audit_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('category')->index();
            $table->string('run_id')->nullable()->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->index(['status', 'reserved_at'], 'swarm_audit_outbox_drain_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_audit_outbox');
    }
};
