<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swarm_durable_runs', function (Blueprint $table): void {
            $table->string('wait_reason')->nullable()->after('timed_out_at');
            $table->timestamp('waiting_since')->nullable()->after('wait_reason');
            $table->timestamp('wait_timeout_at')->nullable()->after('waiting_since');
            $table->timestamp('last_progress_at')->nullable()->after('wait_timeout_at');
            $table->json('retry_policy')->nullable()->after('last_progress_at');
            $table->unsignedInteger('retry_attempt')->default(0)->after('retry_policy');
            $table->timestamp('next_retry_at')->nullable()->after('retry_attempt');
            $table->string('parent_run_id')->nullable()->after('next_retry_at');

            $table->index(['status', 'wait_timeout_at'], 'swarm_durable_runs_wait_timeout_idx');
            $table->index(['parent_run_id'], 'swarm_durable_runs_parent_run_idx');
            $table->index(['next_retry_at'], 'swarm_durable_runs_next_retry_idx');
        });

        Schema::table('swarm_durable_branches', function (Blueprint $table): void {
            $table->timestamp('last_progress_at')->nullable()->after('finished_at');
            $table->json('retry_policy')->nullable()->after('last_progress_at');
            $table->unsignedInteger('retry_attempt')->default(0)->after('retry_policy');
            $table->timestamp('next_retry_at')->nullable()->after('retry_attempt');

            $table->index(['next_retry_at'], 'swarm_durable_branches_next_retry_idx');
        });

        Schema::create('swarm_durable_signals', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->string('name')->index();
            $table->string('status')->default('recorded')->index();
            $table->json('payload')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->unsignedInteger('consumed_step_index')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'idempotency_key'], 'swarm_durable_signals_idempotency_idx');
            $table->index(['run_id', 'name', 'status'], 'swarm_durable_signals_run_name_status_idx');
        });

        Schema::create('swarm_durable_waits', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->string('name')->index();
            $table->string('status')->default('waiting')->index();
            $table->string('reason')->nullable();
            $table->timestamp('timeout_at')->nullable()->index();
            $table->foreignId('signal_id')->nullable()->index();
            $table->json('outcome')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status', 'timeout_at'], 'swarm_durable_waits_recovery_idx');
        });

        Schema::create('swarm_durable_labels', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->string('key')->index();
            $table->string('value_type');
            $table->string('value_string')->nullable();
            $table->bigInteger('value_integer')->nullable();
            $table->double('value_float')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'key']);
            $table->index(['key', 'value_string'], 'swarm_durable_labels_string_idx');
            $table->index(['key', 'value_integer'], 'swarm_durable_labels_integer_idx');
            $table->index(['key', 'value_boolean'], 'swarm_durable_labels_boolean_idx');
        });

        Schema::create('swarm_durable_details', function (Blueprint $table): void {
            $table->string('run_id')->primary();
            $table->json('details');
            $table->timestamps();
        });

        Schema::create('swarm_durable_progress', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->index();
            $table->string('branch_id')->default('')->index();
            $table->unsignedInteger('step_index')->nullable();
            $table->string('agent_class')->nullable();
            $table->json('progress');
            $table->timestamp('last_progress_at')->index();
            $table->timestamps();

            $table->unique(['run_id', 'branch_id'], 'swarm_durable_progress_latest_idx');
        });

        Schema::create('swarm_durable_child_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('parent_run_id')->index();
            $table->string('child_run_id')->unique();
            $table->string('child_swarm_class');
            $table->string('wait_name')->index();
            $table->json('context_payload');
            $table->string('status')->default('pending')->index();
            $table->longText('output')->nullable();
            $table->json('failure')->nullable();
            $table->timestamp('dispatched_at')->nullable()->index();
            $table->timestamp('terminal_event_dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['parent_run_id', 'status'], 'swarm_durable_child_runs_parent_status_idx');
            $table->index(['status', 'dispatched_at'], 'swarm_durable_child_runs_dispatch_idx');
        });

        Schema::create('swarm_durable_webhook_idempotency', function (Blueprint $table): void {
            $table->id();
            $table->string('scope');
            $table->string('idempotency_key');
            $table->string('request_hash');
            $table->string('status')->default('reserved')->index();
            $table->string('run_id')->nullable()->index();
            $table->json('response_payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'idempotency_key'], 'swarm_webhook_idempotency_scope_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swarm_durable_webhook_idempotency');
        Schema::dropIfExists('swarm_durable_child_runs');
        Schema::dropIfExists('swarm_durable_progress');
        Schema::dropIfExists('swarm_durable_details');
        Schema::dropIfExists('swarm_durable_labels');
        Schema::dropIfExists('swarm_durable_waits');
        Schema::dropIfExists('swarm_durable_signals');

        Schema::table('swarm_durable_branches', function (Blueprint $table): void {
            $table->dropIndex('swarm_durable_branches_next_retry_idx');
            $table->dropColumn(['last_progress_at', 'retry_policy', 'retry_attempt', 'next_retry_at']);
        });

        Schema::table('swarm_durable_runs', function (Blueprint $table): void {
            $table->dropIndex('swarm_durable_runs_wait_timeout_idx');
            $table->dropIndex('swarm_durable_runs_parent_run_idx');
            $table->dropIndex('swarm_durable_runs_next_retry_idx');
            $table->dropColumn([
                'wait_reason',
                'waiting_since',
                'wait_timeout_at',
                'last_progress_at',
                'retry_policy',
                'retry_attempt',
                'next_retry_at',
                'parent_run_id',
            ]);
        });
    }
};
