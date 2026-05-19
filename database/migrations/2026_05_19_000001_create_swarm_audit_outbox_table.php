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
 * may outlive the run that produced them. Rows expire when explicitly pruned
 * by an operator or by swarm:prune retention policy.
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
