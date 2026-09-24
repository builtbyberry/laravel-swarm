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
    config()->set('database.connections.testing.database', $database);
    config()->set('ai.conversations.connection', 'testing');
    config()->set('ai.conversations.generate_title', false);
    config()->set('ai.providers.openai.key', 'proof-key');

    DB::purge('testing');
    DB::reconnect('testing');
    DB::statement('PRAGMA journal_mode=WAL');
    DB::statement('PRAGMA busy_timeout=10000');
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
        $table->unsignedInteger('revision');
        $table->unsignedInteger('fence');
        $table->string('decision_digest');
        $table->string('state');
        $table->timestamps();
        $table->unique(['run_id', 'revision']);
    });
    Schema::create('native_approval_proof_effects', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('operation_key');
        $table->string('value');
        $table->timestamp('created_at');
    });
    Schema::create('native_approval_proof_receipts', function (Blueprint $table): void {
        $table->string('run_id')->primary();
        $table->string('conversation_id');
        $table->string('assistant_message_id');
        $table->string('result_digest');
        $table->text('usage');
        $table->timestamp('created_at');
    });
    Schema::create('native_approval_proof_checkpoints', function (Blueprint $table): void {
        $table->string('run_id')->primary();
        $table->unsignedInteger('fence');
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

function nativeApprovalProofResume(string $boundary): void
{
    $wait = DB::table('native_approval_proof_waits')->where('run_id', 'approval-run')->sole();

    if ($boundary === 'before_persisted_intent') {
        CrashBarrier::trip($boundary);
    }

    DB::table('native_approval_proof_intents')->insert([
        'idempotency_key' => 'approval-intent-key',
        'run_id' => $wait->run_id,
        'revision' => $wait->revision,
        'fence' => $wait->fence,
        'decision_digest' => hash('sha256', 'provider-item-approval:approve'),
        'state' => 'recorded',
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    if ($boundary === 'intent_persisted_worker_not_started') {
        CrashBarrier::trip($boundary);
    }

    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(nativeApprovalCompletedResponse())]);

    $response = (new CrashApprovalAgent)
        ->continue($wait->conversation_id, new CrashParticipant((int) $wait->participant_id))
        ->prompt(Decisions::from([$wait->tool_call_id => true]));

    $resultDigest = hash('sha256', $response->text.'|'.json_encode($response->usage->toArray(), JSON_THROW_ON_ERROR));

    DB::table('native_approval_proof_receipts')->insert([
        'run_id' => $wait->run_id,
        'conversation_id' => $response->conversationId,
        'assistant_message_id' => $response->assistantMessageId,
        'result_digest' => $resultDigest,
        'usage' => json_encode($response->usage->toArray(), JSON_THROW_ON_ERROR),
        'created_at' => now('UTC'),
    ]);

    if ($boundary === 'after_native_completion_before_checkpoint') {
        CrashBarrier::trip($boundary);
    }

    DB::table('native_approval_proof_checkpoints')->insert([
        'run_id' => $wait->run_id,
        'fence' => $wait->fence,
        'result_digest' => $resultDigest,
        'output' => $response->text,
        'created_at' => now('UTC'),
    ]);

    if ($boundary === 'after_checkpoint_before_ack') {
        CrashBarrier::trip($boundary);
    }

    throw new RuntimeException("Boundary [{$boundary}] did not terminate the worker.");
}

function nativeApprovalProofRecover(string $boundary): void
{
    $wait = DB::table('native_approval_proof_waits')->where('run_id', 'approval-run')->sole();
    $intent = DB::table('native_approval_proof_intents')->where('run_id', $wait->run_id)->first();
    $effectCount = DB::table('native_approval_proof_effects')->count();
    $receipt = DB::table('native_approval_proof_receipts')->where('run_id', $wait->run_id)->first();
    $checkpoint = DB::table('native_approval_proof_checkpoints')->where('run_id', $wait->run_id)->first();
    $evidence = ['intent' => $intent?->state, 'effects' => $effectCount, 'receipt' => $receipt !== null, 'checkpoint' => $checkpoint !== null];

    $classification = match ($boundary) {
        'before_persisted_intent' => 'safe_to_resubmit',
        'intent_persisted_worker_not_started' => 'recover_recorded_intent',
        'before_tool_effect' => 'safe_to_retry_recorded_intent',
        'after_effect_before_result' => 'indeterminate_reconciliation_required',
        'after_saved_result_before_model' => 'saved_result_requires_continuation_primitive',
        'after_native_completion_before_checkpoint' => 'reconcile_validated_native_receipt',
        'after_checkpoint_before_ack' => 'return_existing_checkpoint',
    };

    if ($boundary === 'after_saved_result_before_model') {
        config()->set('tests.native_approval_recovering', true);
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

    if ($boundary === 'after_native_completion_before_checkpoint') {
        $stored = DB::table('agent_conversation_messages')->where('id', $receipt->assistant_message_id)->sole();
        expect($stored->status)->toBe('completed')
            ->and(hash('sha256', $stored->content.'|'.$receipt->usage))->toBe($receipt->result_digest);

        DB::table('native_approval_proof_checkpoints')->insertOrIgnore([
            'run_id' => $wait->run_id,
            'fence' => $wait->fence,
            'result_digest' => $receipt->result_digest,
            'output' => $stored->content,
            'created_at' => now('UTC'),
        ]);
        $evidence['checkpoint'] = true;
    }

    DB::table('native_approval_proof_recoveries')->insert([
        'run_id' => $wait->run_id,
        'classification' => $classification,
        'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
        'created_at' => now('UTC'),
    ]);
}

function nativeApprovalProofProcess(string $mode, string $database, string $boundary): Process
{
    return new Process(
        [PHP_BINARY, 'vendor/bin/pest', 'tests/Feature/Adoption/NativeApprovalRecoveryCrashTest.php', '--filter=native approval crash worker', '--colors=never'],
        dirname(__DIR__, 3),
        [
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
            'resume' => nativeApprovalProofResume($boundary),
            'recover' => nativeApprovalProofRecover($boundary),
            default => throw new InvalidArgumentException("Unknown crash worker mode [{$mode}]."),
        };
    });
} else {
    test('fresh processes preserve an honest recovery classification at every native approval crash boundary', function (string $boundary, string $classification): void {
        expect(function_exists('posix_kill'))->toBeTrue();

        $directory = sys_get_temp_dir().'/swarm-native-approval-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/proof.sqlite';
        touch($database);

        try {
            $setup = nativeApprovalProofProcess('setup', $database, $boundary);
            $setup->run();
            expect($setup->getExitCode())->toBe(0, $setup->getErrorOutput().$setup->getOutput());

            $resume = nativeApprovalProofProcess('resume', $database, $boundary);
            $resume->start();
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
                $resume->wait();
            } catch (ProcessSignaledException) {
                // SIGKILL is the expected proof boundary, not a test-worker failure.
            }

            expect($resume->isSuccessful())->toBeFalse()
                ->and($resume->hasBeenSignaled() ? $resume->getTermSignal() : $resume->getExitCode())->toBeIn([9, 137]);

            $recover = nativeApprovalProofProcess('recover', $database, $boundary);
            $recover->run();
            expect($recover->getExitCode())->toBe(0, $recover->getErrorOutput().$recover->getOutput());

            $recovery = $pdo->query("SELECT * FROM native_approval_proof_recoveries WHERE run_id = 'approval-run'")->fetch(PDO::FETCH_ASSOC);
            $evidence = json_decode($recovery['evidence'], true, 512, JSON_THROW_ON_ERROR);

            expect($recovery['classification'])->toBe($classification);

            match ($boundary) {
                'before_persisted_intent' => expect($evidence)->toMatchArray(['intent' => null, 'effects' => 0]),
                'intent_persisted_worker_not_started', 'before_tool_effect' => expect($evidence)->toMatchArray(['intent' => 'recorded', 'effects' => 0]),
                'after_effect_before_result' => expect($evidence)->toMatchArray(['effects' => 1, 'receipt' => false, 'checkpoint' => false]),
                'after_saved_result_before_model' => expect($evidence['replay'])->toContain('already-resolved'),
                'after_native_completion_before_checkpoint' => expect($evidence)->toMatchArray(['effects' => 1, 'receipt' => true, 'checkpoint' => true]),
                'after_checkpoint_before_ack' => expect($evidence)->toMatchArray(['effects' => 1, 'receipt' => true, 'checkpoint' => true]),
            };
        } finally {
            isset($resume) && $resume->isRunning() ? $resume->stop() : null;
            @unlink($database.'-shm');
            @unlink($database.'-wal');
            @unlink($database);
            @rmdir($directory);
        }
    })->with([
        ['before_persisted_intent', 'safe_to_resubmit'],
        ['intent_persisted_worker_not_started', 'recover_recorded_intent'],
        ['before_tool_effect', 'safe_to_retry_recorded_intent'],
        ['after_effect_before_result', 'indeterminate_reconciliation_required'],
        ['after_saved_result_before_model', 'saved_result_requires_continuation_primitive'],
        ['after_native_completion_before_checkpoint', 'reconcile_validated_native_receipt'],
        ['after_checkpoint_before_ack', 'return_existing_checkpoint'],
    ]);

}
