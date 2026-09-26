<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\NativeApprovalRecovery\CrashApprovalAgent;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\NativeApprovalRecovery\CrashBarrier;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\NativeApprovalRecovery\CrashParticipant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

pest()->group('native-approval-recovery');

function nativeApprovalProofConfigureDatabase(string $database): void
{
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 10_000,
        'journal_mode' => 'WAL',
        'synchronous' => 'NORMAL',
    ]);
    config()->set('ai.conversations.connection', 'testing');
    config()->set('ai.conversations.generate_title', false);
    config()->set('ai.providers.openai.key', 'proof-key');
    config()->set('ai.providers.openai.url', 'https://api.openai.com/v1');

    DB::purge('testing');
    DB::reconnect('testing');
    DB::statement('PRAGMA busy_timeout=10000');

    if (DB::connection()->getDriverName() !== 'sqlite') {
        throw new RuntimeException('The native approval crash proof requires an isolated SQLite connection.');
    }
}

function nativeApprovalProofMigrate(): void
{
    if (! Schema::hasTable('agent_conversations')) {
        (require dirname(__DIR__, 3).'/vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php')->up();
    }

    if (Schema::hasTable('native_approval_proof_waits')) {
        return;
    }

    Schema::create('native_approval_proof_waits', function (Blueprint $table): void {
        $table->string('run_id')->primary();
        $table->string('tenant_id');
        $table->string('conversation_id');
        $table->string('assistant_message_id');
        $table->string('participant_type');
        $table->string('participant_id');
        $table->string('tool_call_id');
        $table->string('arguments_digest');
        $table->unsignedInteger('revision');
        $table->unsignedInteger('fence');
        $table->string('state');
        $table->timestamps();
    });
    Schema::create('native_approval_proof_intents', function (Blueprint $table): void {
        $table->string('idempotency_key')->primary();
        $table->string('run_id');
        $table->string('tenant_id');
        $table->unsignedInteger('revision');
        $table->unsignedInteger('fence');
        $table->string('decision_digest');
        $table->string('state');
        $table->timestamps();
        $table->unique(['run_id', 'revision']);
    });
    Schema::create('native_approval_proof_dispatches', function (Blueprint $table): void {
        $table->string('run_id')->primary();
        $table->unsignedInteger('revision');
        $table->unsignedInteger('fence');
        $table->string('state');
        $table->timestamps();
    });
    Schema::create('native_approval_proof_effects', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('operation_key');
        $table->string('value');
        $table->timestamp('created_at');
    });
    Schema::create('native_approval_proof_receipts', function (Blueprint $table): void {
        $table->string('run_id')->primary();
        $table->string('tenant_id');
        $table->string('conversation_id');
        $table->string('assistant_message_id');
        $table->string('participant_type');
        $table->string('participant_id');
        $table->string('tool_call_id');
        $table->unsignedInteger('revision');
        $table->unsignedInteger('fence');
        $table->string('decision_digest');
        $table->string('result_digest');
        $table->text('usage');
        $table->timestamp('created_at');
    });
    Schema::create('native_approval_proof_checkpoints', function (Blueprint $table): void {
        $table->string('run_id')->primary();
        $table->string('tenant_id');
        $table->string('conversation_id');
        $table->string('assistant_message_id');
        $table->unsignedInteger('revision');
        $table->unsignedInteger('fence');
        $table->string('decision_digest');
        $table->string('result_digest');
        $table->text('output');
        $table->timestamp('created_at');
    });
    Schema::create('native_approval_proof_barriers', function (Blueprint $table): void {
        $table->string('name')->primary();
        $table->timestamp('created_at');
    });
    Schema::create('native_approval_proof_releases', function (Blueprint $table): void {
        $table->string('name')->primary();
        $table->timestamp('created_at');
    });
    Schema::create('native_approval_proof_recoveries', function (Blueprint $table): void {
        $table->string('run_id')->primary();
        $table->string('classification');
        $table->text('evidence');
        $table->timestamp('created_at');
    });
}

function nativeApprovalPendingResponse(): array
{
    return [
        'id' => 'response-pending',
        'model' => 'gpt-4.1-mini',
        'status' => 'completed',
        'output' => [[
            'type' => 'function_call',
            'id' => 'provider-item-approval',
            'call_id' => 'approval-call',
            'name' => 'CrashApprovalTool',
            'arguments' => '{"value":"controlled-value"}',
        ]],
        'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
    ];
}

function nativeApprovalCompletedResponse(): array
{
    return [
        'id' => 'response-completed',
        'model' => 'gpt-4.1-mini',
        'status' => 'completed',
        'output' => [[
            'type' => 'message',
            'id' => 'provider-message-completed',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => 'native completion']],
        ]],
        'usage' => ['input_tokens' => 5, 'output_tokens' => 7],
    ];
}

function nativeApprovalProofDecisionDigest(): string
{
    return hash('sha256', json_encode([
        'pending' => [
            'provider-item-approval' => [
                'action' => 'approve',
                'arguments_digest' => hash('sha256', '{"value":"controlled-value"}'),
                'result' => null,
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function nativeApprovalProofSetup(): void
{
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(nativeApprovalPendingResponse())]);

    $participant = new CrashParticipant;
    $response = (new CrashApprovalAgent)->forParticipant($participant)->prompt('perform the controlled effect');

    expect($response->hasPendingApprovals())->toBeTrue()
        ->and($response->conversationId)->not->toBeNull()
        ->and($response->assistantMessageId)->not->toBeNull();

    DB::table('native_approval_proof_waits')->insert([
        'run_id' => 'approval-run',
        'tenant_id' => 'tenant-a',
        'conversation_id' => $response->conversationId,
        'assistant_message_id' => $response->assistantMessageId,
        'participant_type' => CrashParticipant::class,
        'participant_id' => (string) $participant->id,
        'tool_call_id' => 'provider-item-approval',
        'arguments_digest' => hash('sha256', '{"value":"controlled-value"}'),
        'revision' => 1,
        'fence' => 1,
        'state' => 'pending',
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

function nativeApprovalProofValidateWait(object $wait): object
{
    if ($wait->state !== 'pending') {
        throw new RuntimeException('Approval wait is no longer actionable.');
    }

    $conversation = DB::table('agent_conversations')->where('id', $wait->conversation_id)->sole();
    if ($conversation->participant_type !== $wait->participant_type
        || (string) $conversation->participant_id !== (string) $wait->participant_id) {
        throw new RuntimeException('Approval conversation ownership no longer matches.');
    }

    $message = DB::table('agent_conversation_messages')
        ->where('id', $wait->assistant_message_id)
        ->where('conversation_id', $wait->conversation_id)
        ->where('participant_type', $wait->participant_type)
        ->where('participant_id', $wait->participant_id)
        ->where('agent', CrashApprovalAgent::class)
        ->sole();
    $steps = json_decode($message->steps, true, flags: JSON_THROW_ON_ERROR);
    $call = collect($steps)->flatMap(fn (array $step): array => $step['tool_calls'] ?? [])
        ->firstWhere('id', $wait->tool_call_id);

    if (! is_array($call)
        || hash('sha256', json_encode($call['arguments'] ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) !== $wait->arguments_digest) {
        throw new RuntimeException('Approval call identity or arguments changed.');
    }

    return $message;
}

function nativeApprovalProofIngress(string $boundary): void
{
    if ($boundary === 'before_persisted_intent') {
        CrashBarrier::trip($boundary);
    }

    $wait = DB::table('native_approval_proof_waits')->where('run_id', 'approval-run')->sole();
    nativeApprovalProofValidateWait($wait);

    DB::transaction(function () use ($wait): void {
        DB::table('native_approval_proof_intents')->insert([
            'idempotency_key' => 'approval-intent-key',
            'run_id' => $wait->run_id,
            'tenant_id' => $wait->tenant_id,
            'revision' => $wait->revision,
            'fence' => $wait->fence,
            'decision_digest' => nativeApprovalProofDecisionDigest(),
            'state' => 'recorded',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('native_approval_proof_dispatches')->insert([
            'run_id' => $wait->run_id,
            'revision' => $wait->revision,
            'fence' => $wait->fence,
            'state' => 'pending',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    });

    if ($boundary === 'intent_persisted_worker_not_started') {
        CrashBarrier::trip($boundary);
    }
}

function nativeApprovalProofResume(string $boundary): void
{
    $wait = DB::transaction(function (): object {
        $wait = DB::table('native_approval_proof_waits')->where('run_id', 'approval-run')->lockForUpdate()->sole();
        nativeApprovalProofValidateWait($wait);
        $intent = DB::table('native_approval_proof_intents')->where('run_id', $wait->run_id)->sole();
        $dispatch = DB::table('native_approval_proof_dispatches')->where('run_id', $wait->run_id)->sole();

        if ((int) $intent->revision !== (int) $wait->revision
            || (int) $intent->fence !== (int) $wait->fence
            || $intent->tenant_id !== $wait->tenant_id
            || $intent->decision_digest !== nativeApprovalProofDecisionDigest()
            || $intent->state !== 'recorded'
            || (int) $dispatch->revision !== (int) $wait->revision
            || (int) $dispatch->fence !== (int) $wait->fence
            || $dispatch->state !== 'pending') {
            throw new RuntimeException('Recorded approval intent is stale or inconsistent.');
        }

        DB::table('native_approval_proof_intents')->where('run_id', $wait->run_id)->update([
            'state' => 'executing',
            'updated_at' => now('UTC'),
        ]);
        DB::table('native_approval_proof_dispatches')->where('run_id', $wait->run_id)->update([
            'state' => 'claimed',
            'updated_at' => now('UTC'),
        ]);

        return $wait;
    });

    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(nativeApprovalCompletedResponse())]);
    $response = (new CrashApprovalAgent)
        ->continue($wait->conversation_id, new CrashParticipant((int) $wait->participant_id))
        ->prompt(Decisions::from([$wait->tool_call_id => true]));

    if ($boundary === 'after_native_completion_before_checkpoint') {
        CrashBarrier::trip($boundary);
    }

    $resultDigest = hash('sha256', $response->text.'|'.json_encode($response->usage->toArray(), JSON_THROW_ON_ERROR));
    DB::table('native_approval_proof_receipts')->insert([
        'run_id' => $wait->run_id,
        'tenant_id' => $wait->tenant_id,
        'conversation_id' => $response->conversationId,
        'assistant_message_id' => $response->assistantMessageId,
        'participant_type' => $wait->participant_type,
        'participant_id' => $wait->participant_id,
        'tool_call_id' => $wait->tool_call_id,
        'revision' => $wait->revision,
        'fence' => $wait->fence,
        'decision_digest' => nativeApprovalProofDecisionDigest(),
        'result_digest' => $resultDigest,
        'usage' => json_encode($response->usage->toArray(), JSON_THROW_ON_ERROR),
        'created_at' => now('UTC'),
    ]);

    DB::table('native_approval_proof_checkpoints')->insert([
        'run_id' => $wait->run_id,
        'tenant_id' => $wait->tenant_id,
        'conversation_id' => $response->conversationId,
        'assistant_message_id' => $response->assistantMessageId,
        'revision' => $wait->revision,
        'fence' => $wait->fence,
        'decision_digest' => nativeApprovalProofDecisionDigest(),
        'result_digest' => $resultDigest,
        'output' => $response->text,
        'created_at' => now('UTC'),
    ]);

    if ($boundary === 'after_checkpoint_before_ack') {
        CrashBarrier::trip($boundary);
    }

    throw new RuntimeException("Boundary [{$boundary}] did not terminate the worker.");
}

function nativeApprovalProofHasSavedResult(?object $message, string $toolCallId): bool
{
    if ($message === null) {
        return false;
    }

    $steps = json_decode($message->steps, true, flags: JSON_THROW_ON_ERROR);

    return collect($steps)->flatMap(fn (array $step): array => $step['tool_calls'] ?? [])
        ->contains(fn (array $call): bool => ($call['id'] ?? null) === $toolCallId && array_key_exists('result', $call));
}

function nativeApprovalProofAssertBound(object $wait, object $record, string $kind): void
{
    foreach (['run_id', 'tenant_id', 'conversation_id', 'assistant_message_id', 'revision', 'fence', 'decision_digest'] as $field) {
        $expected = match ($field) {
            'decision_digest' => nativeApprovalProofDecisionDigest(),
            default => $wait->{$field},
        };

        if ((string) $record->{$field} !== (string) $expected) {
            throw new RuntimeException("{$kind} is not bound to the active approval {$field}.");
        }
    }
}

function nativeApprovalProofAssertReceiptBound(object $wait, object $receipt): void
{
    nativeApprovalProofAssertBound($wait, $receipt, 'Receipt');

    foreach (['participant_type', 'participant_id', 'tool_call_id'] as $field) {
        if ((string) $receipt->{$field} !== (string) $wait->{$field}) {
            throw new RuntimeException("Receipt is not bound to the active approval {$field}.");
        }
    }
}

function nativeApprovalProofRecover(): void
{
    config()->set('tests.native_approval_recovering', true);
    $wait = DB::table('native_approval_proof_waits')->where('run_id', 'approval-run')->sole();
    $native = nativeApprovalProofValidateWait($wait);
    $intent = DB::table('native_approval_proof_intents')->where('run_id', $wait->run_id)->first();
    $dispatch = DB::table('native_approval_proof_dispatches')->where('run_id', $wait->run_id)->first();
    $effectCount = DB::table('native_approval_proof_effects')->count();
    $receipt = DB::table('native_approval_proof_receipts')->where('run_id', $wait->run_id)->first();
    $checkpoint = DB::table('native_approval_proof_checkpoints')->where('run_id', $wait->run_id)->first();
    $hasSavedResult = nativeApprovalProofHasSavedResult($native, $wait->tool_call_id);

    if ($checkpoint !== null) {
        nativeApprovalProofAssertBound($wait, $checkpoint, 'Checkpoint');
        if ($receipt === null) {
            throw new RuntimeException('Checkpoint has no validating receipt.');
        }
        nativeApprovalProofAssertReceiptBound($wait, $receipt);
        if ($checkpoint->result_digest !== $receipt->result_digest) {
            throw new RuntimeException('Checkpoint and receipt result digests conflict.');
        }
        $classification = 'return_existing_checkpoint';
    } elseif ($native->status === 'completed') {
        $usage = $receipt?->usage ?? $native->usage;
        $resultDigest = hash('sha256', $native->content.'|'.$usage);

        if ($receipt !== null) {
            nativeApprovalProofAssertReceiptBound($wait, $receipt);
            if ($receipt->result_digest !== $resultDigest) {
                throw new RuntimeException('Receipt does not match the completed native message.');
            }
            $classification = 'reconcile_validated_native_receipt';
        } else {
            DB::table('native_approval_proof_receipts')->insert([
                'run_id' => $wait->run_id,
                'tenant_id' => $wait->tenant_id,
                'conversation_id' => $wait->conversation_id,
                'assistant_message_id' => $wait->assistant_message_id,
                'participant_type' => $wait->participant_type,
                'participant_id' => $wait->participant_id,
                'tool_call_id' => $wait->tool_call_id,
                'revision' => $wait->revision,
                'fence' => $wait->fence,
                'decision_digest' => nativeApprovalProofDecisionDigest(),
                'result_digest' => $resultDigest,
                'usage' => $usage,
                'created_at' => now('UTC'),
            ]);
            $classification = 'reconcile_validated_native_completion';
        }

        DB::table('native_approval_proof_checkpoints')->insert([
            'run_id' => $wait->run_id,
            'tenant_id' => $wait->tenant_id,
            'conversation_id' => $wait->conversation_id,
            'assistant_message_id' => $wait->assistant_message_id,
            'revision' => $wait->revision,
            'fence' => $wait->fence,
            'decision_digest' => nativeApprovalProofDecisionDigest(),
            'result_digest' => $resultDigest,
            'output' => $native->content,
            'created_at' => now('UTC'),
        ]);
        $checkpoint = DB::table('native_approval_proof_checkpoints')->where('run_id', $wait->run_id)->sole();
        nativeApprovalProofAssertBound($wait, $checkpoint, 'Checkpoint');
    } elseif ($hasSavedResult) {
        $classification = 'saved_result_requires_continuation_primitive';
    } elseif ($intent === null && $dispatch === null && $effectCount === 0) {
        $classification = 'safe_to_resubmit';
    } elseif ($intent !== null
        && $dispatch !== null
        && $intent->state === 'recorded'
        && $dispatch->state === 'pending'
        && $effectCount === 0
        && (int) $intent->revision === (int) $wait->revision
        && (int) $intent->fence === (int) $wait->fence
        && $intent->tenant_id === $wait->tenant_id
        && $intent->decision_digest === nativeApprovalProofDecisionDigest()) {
        $classification = 'recover_recorded_intent';
    } elseif ($intent !== null
        && $dispatch !== null
        && $intent->state === 'executing'
        && $dispatch->state === 'claimed') {
        $classification = 'indeterminate_reconciliation_required';
    } else {
        throw new RuntimeException('Approval recovery evidence is contradictory or unknown.');
    }

    $evidence = [
        'intent' => $intent?->state,
        'dispatch' => $dispatch?->state,
        'effects' => $effectCount,
        'saved_result' => $hasSavedResult,
        'native_status' => $native->status,
        'receipt' => DB::table('native_approval_proof_receipts')->where('run_id', $wait->run_id)->exists(),
        'checkpoint' => DB::table('native_approval_proof_checkpoints')->where('run_id', $wait->run_id)->exists(),
    ];

    if ($classification === 'saved_result_requires_continuation_primitive') {
        Http::preventStrayRequests();
        try {
            (new CrashApprovalAgent)
                ->continue($wait->conversation_id, new CrashParticipant((int) $wait->participant_id))
                ->prompt(Decisions::from([$wait->tool_call_id => true]));
            $evidence['replay'] = 'unexpected-success';
        } catch (ApprovalMismatchException $exception) {
            $evidence['replay'] = $exception->getMessage();
        }
    }

    DB::table('native_approval_proof_recoveries')->insert([
        'run_id' => $wait->run_id,
        'classification' => $classification,
        'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
        'created_at' => now('UTC'),
    ]);
}

function nativeApprovalProofProcess(string $mode, string $database, string $boundary = ''): Process
{
    return new Process(
        [PHP_BINARY, 'vendor/bin/pest', 'tests/Feature/Adoption/NativeApprovalRecoveryCrashTest.php', '--filter=native approval crash worker', '--colors=never'],
        dirname(__DIR__, 3),
        [
            'DB_CONNECTION' => 'sqlite',
            'SWARM_NATIVE_APPROVAL_CRASH_WORKER' => $mode,
            'SWARM_NATIVE_APPROVAL_DB' => $database,
            'SWARM_NATIVE_APPROVAL_BOUNDARY' => $boundary,
        ],
        timeout: 30,
    );
}

function nativeApprovalProofPdo(string $database): PDO
{
    $pdo = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout=10000');

    return $pdo;
}

if (getenv('SWARM_NATIVE_APPROVAL_CRASH_WORKER') !== false) {
    test('native approval crash worker', function (): void {
        $database = (string) getenv('SWARM_NATIVE_APPROVAL_DB');
        $boundary = (string) getenv('SWARM_NATIVE_APPROVAL_BOUNDARY');
        $mode = (string) getenv('SWARM_NATIVE_APPROVAL_CRASH_WORKER');

        nativeApprovalProofConfigureDatabase($database);
        nativeApprovalProofMigrate();

        match ($mode) {
            'setup' => nativeApprovalProofSetup(),
            'ingress' => nativeApprovalProofIngress($boundary),
            'resume' => nativeApprovalProofResume($boundary),
            'recover' => nativeApprovalProofRecover(),
            default => throw new InvalidArgumentException("Unknown crash worker mode [{$mode}]."),
        };
    });
} else {
    test('fresh processes preserve an evidence-derived recovery classification at every native approval crash boundary', function (string $boundary, string $classification, array $expectedEvidence): void {
        if (! function_exists('proc_open') || ! function_exists('posix_kill') || ! defined('SIGKILL')) {
            $this->markTestSkipped('Native approval crash proof requires process spawning and POSIX SIGKILL; Linux CI is authoritative.');
        }

        $directory = sys_get_temp_dir().'/swarm-native-approval-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/proof.sqlite';
        touch($database);

        try {
            $setup = nativeApprovalProofProcess('setup', $database);
            $setup->run();
            expect($setup->getExitCode())->toBe(0, $setup->getErrorOutput().$setup->getOutput());

            if (in_array($boundary, ['before_persisted_intent', 'intent_persisted_worker_not_started'], true)) {
                $crashing = nativeApprovalProofProcess('ingress', $database, $boundary);
            } else {
                $ingress = nativeApprovalProofProcess('ingress', $database, $boundary);
                $ingress->run();
                expect($ingress->getExitCode())->toBe(0, $ingress->getErrorOutput().$ingress->getOutput());
                $crashing = nativeApprovalProofProcess('resume', $database, $boundary);
            }

            $crashing->start();
            $pdo = nativeApprovalProofPdo($database);
            $deadline = microtime(true) + 15;
            $observed = false;

            while (microtime(true) < $deadline) {
                try {
                    $observed = (bool) $pdo->query('SELECT 1 FROM native_approval_proof_barriers WHERE name = '.$pdo->quote($boundary))->fetchColumn();
                } catch (PDOException) {
                    $observed = false;
                }

                if ($observed) {
                    break;
                }

                usleep(10_000);
            }

            expect($observed)->toBeTrue("Worker never reached flushed boundary [{$boundary}].");
            $statement = $pdo->prepare('INSERT INTO native_approval_proof_releases (name, created_at) VALUES (?, ?)');
            $statement->execute([$boundary, gmdate('Y-m-d H:i:s')]);
            try {
                $crashing->wait();
            } catch (ProcessSignaledException) {
                // SIGKILL is the expected proof boundary, not a test-worker failure.
            }

            expect($crashing->isSuccessful())->toBeFalse()
                ->and($crashing->hasBeenSignaled() ? $crashing->getTermSignal() : $crashing->getExitCode())->toBeIn([9, 137]);

            $recover = nativeApprovalProofProcess('recover', $database);
            $recover->run();
            expect($recover->getExitCode())->toBe(0, $recover->getErrorOutput().$recover->getOutput());

            $recovery = $pdo->query("SELECT * FROM native_approval_proof_recoveries WHERE run_id = 'approval-run'")->fetch(PDO::FETCH_ASSOC);
            $evidence = json_decode($recovery['evidence'], true, 512, JSON_THROW_ON_ERROR);
            expect($recovery['classification'])->toBe($classification)
                ->and($evidence)->toMatchArray($expectedEvidence)
                ->and(array_keys($evidence))->toContain('intent', 'dispatch', 'effects', 'saved_result', 'native_status', 'receipt', 'checkpoint');

            if ($boundary === 'after_saved_result_before_model') {
                expect($evidence['replay'])->toContain('already-resolved');
            }
        } finally {
            isset($crashing) && $crashing->isRunning() ? $crashing->stop() : null;
            @unlink($database.'-shm');
            @unlink($database.'-wal');
            @unlink($database);
            @rmdir($directory);
        }
    })->with([
        ['before_persisted_intent', 'safe_to_resubmit', ['intent' => null, 'dispatch' => null, 'effects' => 0, 'saved_result' => false, 'native_status' => 'paused', 'receipt' => false, 'checkpoint' => false]],
        ['intent_persisted_worker_not_started', 'recover_recorded_intent', ['intent' => 'recorded', 'dispatch' => 'pending', 'effects' => 0, 'saved_result' => false, 'native_status' => 'paused', 'receipt' => false, 'checkpoint' => false]],
        ['before_tool_effect', 'indeterminate_reconciliation_required', ['intent' => 'executing', 'dispatch' => 'claimed', 'effects' => 0, 'saved_result' => false, 'native_status' => 'paused', 'receipt' => false, 'checkpoint' => false]],
        ['after_effect_before_result', 'indeterminate_reconciliation_required', ['intent' => 'executing', 'dispatch' => 'claimed', 'effects' => 1, 'saved_result' => false, 'native_status' => 'paused', 'receipt' => false, 'checkpoint' => false]],
        ['after_saved_result_before_model', 'saved_result_requires_continuation_primitive', ['intent' => 'executing', 'dispatch' => 'claimed', 'effects' => 1, 'saved_result' => true, 'native_status' => 'paused', 'receipt' => false, 'checkpoint' => false]],
        ['after_native_completion_before_checkpoint', 'reconcile_validated_native_completion', ['intent' => 'executing', 'dispatch' => 'claimed', 'effects' => 1, 'saved_result' => true, 'native_status' => 'completed', 'receipt' => true, 'checkpoint' => true]],
        ['after_checkpoint_before_ack', 'return_existing_checkpoint', ['intent' => 'executing', 'dispatch' => 'claimed', 'effects' => 1, 'saved_result' => true, 'native_status' => 'completed', 'receipt' => true, 'checkpoint' => true]],
    ]);

    test('receipt and checkpoint evidence must match the active approval identity and fence', function (): void {
        $wait = (object) [
            'run_id' => 'run',
            'tenant_id' => 'tenant-a',
            'conversation_id' => 'conversation',
            'assistant_message_id' => 'assistant',
            'participant_type' => 'user',
            'participant_id' => '42',
            'tool_call_id' => 'approval-call',
            'revision' => 2,
            'fence' => 4,
        ];
        $bound = (object) [
            ...get_object_vars($wait),
            'decision_digest' => nativeApprovalProofDecisionDigest(),
        ];
        nativeApprovalProofAssertReceiptBound($wait, $bound);

        foreach (['run_id', 'tenant_id', 'conversation_id', 'assistant_message_id', 'participant_type', 'participant_id', 'tool_call_id', 'revision', 'fence', 'decision_digest'] as $field) {
            $poisoned = clone $bound;
            $poisoned->{$field} = $field === 'decision_digest' ? str_repeat('0', 64) : 'wrong';
            expect(fn () => nativeApprovalProofAssertReceiptBound($wait, $poisoned))
                ->toThrow(RuntimeException::class, "Receipt is not bound to the active approval {$field}");
        }
    });
}
