<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastNativeAgentSettingsSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeNativeInputSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Telemetry\PackageJobTelemetryState;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingQueuedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// Invoke and broadcast jobs carry the run payload, including the prompt, inline.
// These tests read the bytes the database queue driver actually stores and run
// them back through the real queue worker.

const ENCRYPTED_PAYLOAD_SECRET = 'regulated-prompt-7f3c9a';

function encryptedPayloadQueue(): void
{
    config()->set('queue.connections.swarm-encrypted', [
        'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs',
        'queue' => 'test', 'retry_after' => 90,
    ]);
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

function encryptedPayloadSink(): RecordingSwarmTelemetrySink
{
    $telemetry = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $telemetry);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);

    return $telemetry;
}

/**
 * @return array<string, mixed>
 */
function encryptedPayloadStoredPayload(): array
{
    return json_decode((string) DB::connection('testing')->table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
}

function encryptedPayloadProcess(QueueJobContract $queued, int $maxTries): ?Throwable
{
    try {
        app('queue.worker')->process('swarm-encrypted', $queued, new WorkerOptions(maxTries: $maxTries));
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

/**
 * Re-seal the stored command under a key this application does not have, as a
 * job queued before an APP_KEY rotation without APP_PREVIOUS_KEYS would be.
 */
function encryptedPayloadSealUnderAnotherKey(string $serializedCommand): void
{
    $otherKey = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');
    $payload = encryptedPayloadStoredPayload();
    $payload['data']['command'] = $otherKey->encrypt($serializedCommand);
    DB::connection('testing')->table('jobs')->update(['payload' => json_encode($payload)]);
}

function encryptedPayloadForgetKey(): void
{
    config()->set('app.key', null);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

class QueuedSwarmPayloadEncryptionApplicationJob implements ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void {}
}

function encryptedPayloadJob(string $class, string $swarmClass, string $runId): InvokeSwarm|BroadcastSwarm
{
    $task = RunContext::from(ENCRYPTED_PAYLOAD_SECRET, $runId)->toQueuePayload();

    return is_a($class, BroadcastSwarm::class, true)
        ? new $class($swarmClass, $task, [new Channel('swarm-encrypted')])
        : new $class($swarmClass, $task);
}

beforeEach(fn () => encryptedPayloadQueue());

it('stores the command as ciphertext so the prompt never reaches the queue backend', function (string $class) {
    $job = encryptedPayloadJob($class, FakeSequentialSwarm::class, 'encrypted-wire-run');

    app('queue')->connection('swarm-encrypted')->push($job);

    $raw = (string) DB::connection('testing')->table('jobs')->value('payload');
    $payload = encryptedPayloadStoredPayload();
    $command = $payload['data']['command'];
    $decrypted = unserialize(Crypt::decrypt($command));

    expect($raw)->not->toContain(ENCRYPTED_PAYLOAD_SECRET)
        ->and($command)->not->toStartWith('O:')
        ->and($payload['displayName'])->toBe(FakeSequentialSwarm::class)
        ->and($payload['data']['commandName'])->toBe($class)
        ->and($decrypted)->toBeInstanceOf($class)
        ->and($decrypted->task)->toBe($job->task);
})->with([
    'invoke' => [InvokeSwarm::class],
    'invoke native input' => [InvokeNativeInputSwarm::class],
    'broadcast' => [BroadcastSwarm::class],
    'broadcast native agent settings' => [BroadcastNativeAgentSettingsSwarm::class],
]);

it('runs an encrypted queued swarm dispatched through the package to completion', function () {
    config()->set('swarm.queue.connection', 'swarm-encrypted');
    $telemetry = encryptedPayloadSink();
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    FakeSequentialSwarm::make()->queue(ENCRYPTED_PAYLOAD_SECRET);

    expect((string) DB::connection('testing')->table('jobs')->value('payload'))->not->toContain(ENCRYPTED_PAYLOAD_SECRET);

    $escaped = encryptedPayloadProcess(app('queue')->connection('swarm-encrypted')->pop('test'), maxTries: 1);

    expect($escaped)->toBeNull()
        ->and($telemetry->recordsForCategory('job.completed'))->toHaveCount(1)
        ->and($telemetry->recordsForCategory('run.completed'))->toHaveCount(1);
});

it('still runs a plaintext command written before the upgrade', function (string $class) {
    $telemetry = encryptedPayloadSink();
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
    $job = encryptedPayloadJob($class, FakeSequentialSwarm::class, 'plaintext-upgrade-run');
    app('queue')->connection('swarm-encrypted')->push($job);

    // What a dispatcher on the previous release stored: the command unencrypted.
    $payload = encryptedPayloadStoredPayload();
    $payload['data']['command'] = serialize(clone $job);
    DB::connection('testing')->table('jobs')->update(['payload' => json_encode($payload)]);

    $escaped = encryptedPayloadProcess(app('queue')->connection('swarm-encrypted')->pop('test'), maxTries: 1);

    expect($escaped)->toBeNull()
        ->and($telemetry->recordsForCategory('job.completed'))->toHaveCount(1)
        ->and($telemetry->recordsForCategory('job.completed')[0]['run_id'])->toBe('plaintext-upgrade-run')
        ->and($telemetry->recordsForCategory('job.completed')[0]['job_class'])->toBe($class);
})->with([
    'invoke' => [InvokeSwarm::class],
    'broadcast' => [BroadcastSwarm::class],
]);

it('emits the fallback job.failed for an encrypted job whose handler never ran', function (string $class) {
    $telemetry = encryptedPayloadSink();
    $queue = app('queue')->connection('swarm-encrypted');
    $queue->push(encryptedPayloadJob($class, FakeSequentialSwarm::class, 'encrypted-fallback-run'));
    DB::connection('testing')->table('jobs')->update(['attempts' => 5]);

    $escaped = encryptedPayloadProcess($queue->pop('test'), maxTries: 5);

    $failed = $telemetry->recordsForCategory('job.failed');

    expect($escaped)->toBeInstanceOf(MaxAttemptsExceededException::class)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['run_id'])->toBe('encrypted-fallback-run')
        ->and($failed[0]['job_class'])->toBe($class)
        ->and($failed[0]['swarm_class'])->toBe(FakeSequentialSwarm::class)
        ->and($failed[0]['duration_ms'])->toBeNull()
        ->and(app(PackageJobTelemetryState::class)->pendingCount())->toBe(0);
})->with([
    'invoke' => [InvokeSwarm::class],
    'broadcast' => [BroadcastSwarm::class],
]);

it('does not duplicate the job.failed an encrypted job emitted from its handler', function () {
    $telemetry = encryptedPayloadSink();
    $queue = app('queue')->connection('swarm-encrypted');
    $queue->push(encryptedPayloadJob(InvokeSwarm::class, FailingQueuedSwarm::class, 'encrypted-dedup-run'));

    $escaped = encryptedPayloadProcess($queue->pop('test'), maxTries: 1);

    $failed = $telemetry->recordsForCategory('job.failed');

    expect($escaped)->toBeInstanceOf(RuntimeException::class)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['run_id'])->toBe('encrypted-dedup-run')
        ->and($failed[0]['duration_ms'])->toBeInt()
        ->and(app(PackageJobTelemetryState::class)->pendingCount())->toBe(0);
});

it('reports a job sealed under another key with a degraded job.failed and a warning', function (string $class) {
    $telemetry = encryptedPayloadSink();
    Log::spy();
    $queue = app('queue')->connection('swarm-encrypted');
    $job = encryptedPayloadJob($class, FakeSequentialSwarm::class, 'rotated-key-run');
    $queue->push($job);
    encryptedPayloadSealUnderAnotherKey(serialize(clone $job));

    $escaped = encryptedPayloadProcess($queue->pop('test'), maxTries: 1);

    $failed = $telemetry->recordsForCategory('job.failed');

    expect($escaped)->toBeInstanceOf(DecryptException::class)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['run_id'])->toBeNull()
        ->and($failed[0]['swarm_class'])->toBe(FakeSequentialSwarm::class)
        ->and($failed[0]['job_class'])->toBe($class)
        ->and($failed[0]['exception_class'])->toBe(DecryptException::class)
        ->and($failed[0]['duration_ms'])->toBeNull()
        ->and(json_encode($failed[0]))->not->toContain(ENCRYPTED_PAYLOAD_SECRET);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'could not be decrypted')
        && $context['job_class'] === $class
        && $context['swarm_class'] === FakeSequentialSwarm::class
        && $context['exception_class'] === DecryptException::class);
})->with([
    'invoke' => [InvokeSwarm::class],
    'broadcast' => [BroadcastSwarm::class],
]);

it('leaves the application\'s own encrypted jobs alone', function () {
    $telemetry = encryptedPayloadSink();
    Log::spy();
    $queue = app('queue')->connection('swarm-encrypted');
    $queue->push(new QueuedSwarmPayloadEncryptionApplicationJob);
    encryptedPayloadSealUnderAnotherKey(serialize(new QueuedSwarmPayloadEncryptionApplicationJob));

    $escaped = encryptedPayloadProcess($queue->pop('test'), maxTries: 1);

    expect($escaped)->toBeInstanceOf(DecryptException::class)
        ->and($telemetry->recordsForCategory('job.failed'))->toBeEmpty();

    Log::shouldNotHaveReceived('warning');
});

it('refuses queue() and broadcastOnQueue() without an application key before anything is queued', function (string $method) {
    config()->set('swarm.queue.connection', 'swarm-encrypted');
    encryptedPayloadForgetKey();
    $response = null;
    $thrown = null;

    try {
        $response = $method === 'queue'
            ? FakeSequentialSwarm::make()->queue(ENCRYPTED_PAYLOAD_SECRET)
            : FakeSequentialSwarm::make()->broadcastOnQueue(ENCRYPTED_PAYLOAD_SECRET, [new Channel('swarm-encrypted')]);
    } catch (NonQueueableSwarmException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(NonQueueableSwarmException::class)
        ->and($thrown?->getMessage())->toContain(FakeSequentialSwarm::class)->toContain('APP_KEY')
        ->and($thrown?->getPrevious())->toBeInstanceOf(MissingAppKeyException::class)
        ->and($response)->toBeNull()
        ->and(DB::connection('testing')->table('jobs')->count())->toBe(0);
})->with(['queue', 'broadcastOnQueue']);

it('never stores a job in plaintext when the application key is missing', function () {
    encryptedPayloadForgetKey();

    $push = fn () => app('queue')->connection('swarm-encrypted')
        ->push(encryptedPayloadJob(InvokeSwarm::class, FakeSequentialSwarm::class, 'keyless-run'));

    expect($push)->toThrow(function (RuntimeException $exception) {
        expect($exception->getMessage())->toStartWith('Failed to serialize job of type ['.InvokeSwarm::class.']')
            ->and($exception->getPrevious())->toBeInstanceOf(MissingAppKeyException::class);
    });

    expect(DB::connection('testing')->table('jobs')->count())->toBe(0);
});
