<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeNativeInputSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeNativeInputQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManager;
use BuiltByBerry\LaravelSwarm\Support\NativeInputRecipient;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Files\StoredVideo;
use Laravel\Ai\Messages\UserMessage;

beforeEach(function () {
    config()->set('swarm.native_inputs.enabled', true);
    config()->set('swarm.native_inputs.disk', 'native-inputs-test');
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    Storage::fake('native-inputs-test');
});

test('recoverable native input stores a sealed envelope and an opaque queue reference', function () {
    $context = RunContext::fromTask('legacy')->withAgentInput(
        new UserMessage('secret question', [new Base64Document(base64_encode('secret document'), 'text/plain')]),
        [NativeInputRecipient::parallel(0, textSource: 'original', attachments: [0])],
    );

    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $queuePayload = $context->toQueuePayload();
    $row = DB::table('swarm_native_inputs')->where('id', $queuePayload['native_input_ref'])->first();

    expect($row)->not->toBeNull()
        ->and($row->payload)->toStartWith(SwarmPersistenceCipher::PREFIX)
        ->and($row->payload)->not->toContain('secret question')
        ->and(json_encode($queuePayload))->not->toContain('secret question')
        ->and(json_encode($queuePayload))->not->toContain('secret document');

    $fresh = RunContext::fromPayload($queuePayload);
    $invocation = $fresh->nativeInvocation('parallel:0', 'topology text');

    expect($invocation->prompt)->toBeInstanceOf(UserMessage::class)
        ->and($invocation->prompt->content)->toBe('secret question')
        ->and($invocation->prompt->attachments->first())->toBeInstanceOf(StoredDocument::class)
        ->and(Storage::disk('native-inputs-test')->allFiles('swarm/native-inputs/'.$context->runId))->toHaveCount(1);
});

test('native background work uses capability-specific jobs while legacy queue payloads do not', function () {
    Bus::fake();

    $queued = FakeSequentialSwarm::make()->queue(new UserMessage('native queue'));
    expect($queued->getJob())->toBeInstanceOf(InvokeNativeInputSwarm::class);

    $durable = FakeSequentialSwarm::make()->dispatchDurable(new UserMessage('native durable'));
    expect($durable->getJob())->toBeInstanceOf(AdvanceNativeInputDurableSwarm::class);

    $resume = app(DurableJobDispatcher::class)
        ->makeQueuedResumeJob($durable->runId);
    expect($resume)->toBeInstanceOf(ResumeNativeInputQueuedHierarchicalSwarm::class);

    $legacy = FakeSequentialSwarm::make()->queue('legacy queue');
    expect($legacy->getJob())
        ->toBeInstanceOf(InvokeSwarm::class)
        ->not->toBeInstanceOf(InvokeNativeInputSwarm::class);
});

test('recoverable execution reconstructs every supported attachment family', function (object $attachment, string $storedClass) {
    $context = RunContext::fromTask(new UserMessage('inspect', [$attachment]))
        ->withAgentInput(
            new UserMessage('inspect', [$attachment]),
            [NativeInputRecipient::parallel(0, attachments: [0])],
        );

    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $fresh = RunContext::fromPayload($context->toQueuePayload());
    $restored = $fresh->nativeInvocation('parallel:0', 'topology')->prompt->attachments->first();

    expect($restored)->toBeInstanceOf($storedClass)
        ->and($restored->content())->toBe('native bytes');
})->with([
    'image' => [new Base64Image(base64_encode('native bytes'), 'image/png'), StoredImage::class],
    'document' => [new Base64Document(base64_encode('native bytes'), 'text/plain'), StoredDocument::class],
    'audio' => [new Base64Audio(base64_encode('native bytes'), 'audio/mpeg'), StoredAudio::class],
    'video' => [new Base64Video(base64_encode('native bytes'), 'video/mp4'), StoredVideo::class],
]);

test('unknown operational envelope versions fail closed before invocation', function () {
    $context = RunContext::fromTask(new UserMessage('secret'))->withAgentInput(
        new UserMessage('secret'),
        [NativeInputRecipient::parallel(0)],
    );
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);

    $reference = $context->nativeInputReference();
    $row = DB::table('swarm_native_inputs')->where('id', $reference)->first();
    $cipher = app(SwarmPersistenceCipher::class);
    $payload = json_decode((string) $cipher->openStrict($row->payload), true, 512, JSON_THROW_ON_ERROR);
    $payload['version'] = 99;
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
    DB::table('swarm_native_inputs')->where('id', $reference)->update([
        'payload' => $cipher->seal($encoded),
        'payload_hash' => hash('sha256', $encoded),
    ]);

    $fresh = RunContext::fromPayload($context->toQueuePayload());
    expect(fn () => $fresh->nativeInvocation('parallel:0', 'topology text'))
        ->toThrow(SwarmException::class, 'unsupported format version [99]');
});

test('native envelopes remain bound to their admitted actor and tenant', function () {
    $context = RunContext::fromTask(new UserMessage('secret'))
        ->withActor('user:1')
        ->mergeMetadata(['tenant_id' => 'tenant-a']);
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);

    $fresh = RunContext::fromPayload($context->toQueuePayload())
        ->mergeMetadata(['tenant_id' => 'tenant-b']);

    expect(fn () => $fresh->nativeInvocation('parallel:0', 'topology'))
        ->toThrow(SwarmException::class, 'not authorized for this actor or tenant');
});

test('expired and revoked native envelopes fail before invocation', function (string $state) {
    $context = RunContext::fromTask(new UserMessage('secret'));
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $reference = (string) $context->nativeInputReference();

    if ($state === 'expired') {
        DB::table('swarm_native_inputs')->where('id', $reference)->update(['expires_at' => now()->subMinute()]);
    } else {
        app(NativeInputStore::class)->revoke($reference, $context->runId);
    }

    $fresh = RunContext::fromPayload($context->toQueuePayload());
    expect(fn () => $fresh->nativeInvocation('parallel:0', 'topology'))
        ->toThrow(SwarmException::class, $state === 'expired' ? 'has expired' : 'missing, revoked');
})->with(['expired', 'revoked']);

test('new native input writers remain default off', function () {
    config()->set('swarm.native_inputs.enabled', false);
    $context = RunContext::fromTask(new UserMessage('question'));

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Sequential, ExecutionMode::Run))
        ->toThrow(SwarmException::class, 'Native swarm input is disabled');
});

test('recoverable native inputs require sealed database persistence', function (string $key, mixed $value) {
    config()->set($key, $value);
    $context = RunContext::fromTask(new UserMessage('question'));

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'requires database persistence');
})->with([
    ['swarm.persistence.driver', 'cache'],
    ['swarm.persistence.encrypt_at_rest', false],
]);

test('recoverable attachment policy rejects unsafe sources and limits', function (Closure $message, Closure $configure, string $error) {
    $configure();
    $nativeMessage = $message();
    $context = RunContext::fromTask($nativeMessage)
        ->withAgentInput($nativeMessage, [NativeInputRecipient::parallel(0)]);

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, $error);
})->with([
    'remote source' => [
        fn () => new UserMessage('inspect', [new RemoteDocument('https://example.test/file.pdf')]),
        fn () => null,
        'Remote native attachments are request-local only',
    ],
    'attachment count' => [
        fn () => new UserMessage('inspect', [new Base64Document(base64_encode('a')), new Base64Document(base64_encode('b'))]),
        fn () => config()->set('swarm.native_inputs.max_attachments', 1),
        'accepts at most [1] attachments',
    ],
    'attachment bytes' => [
        fn () => new UserMessage('inspect', [new Base64Document(base64_encode('too-large'))]),
        fn () => config()->set('swarm.native_inputs.max_attachment_bytes', 2),
        'exceeds the configured [2] byte limit',
    ],
]);

test('recoverable stored attachments must use the configured private disk', function () {
    Storage::fake('other-disk');
    Storage::disk('other-disk')->put('document.txt', 'secret');
    $message = new UserMessage('inspect', [new StoredDocument('document.txt', 'other-disk')]);
    $context = RunContext::fromTask($message)
        ->withAgentInput($message, [NativeInputRecipient::parallel(0)]);

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'explicitly configured [swarm.native_inputs.disk]');
});

test('operational envelope identity and run binding fail closed', function (string $mutation, string $error) {
    $context = RunContext::fromTask(new UserMessage('secret'));
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $reference = (string) $context->nativeInputReference();

    DB::table('swarm_native_inputs')->where('id', $reference)->update(match ($mutation) {
        'hash' => ['payload_hash' => str_repeat('0', 64)],
        'run' => ['run_id' => 'different-run'],
    });

    $fresh = RunContext::fromPayload($context->toQueuePayload());
    expect(fn () => $fresh->nativeInvocation('parallel:0', 'topology'))
        ->toThrow(SwarmException::class, $error);
})->with([
    'content hash' => ['hash', 'failed its content identity check'],
    'run binding' => ['run', 'does not belong to run'],
]);

test('native input state transitions are scoped to the owning run', function () {
    $context = RunContext::fromTask(new UserMessage('secret'));
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);

    expect(fn () => app(NativeInputStore::class)->revoke((string) $context->nativeInputReference(), 'different-run'))
        ->toThrow(SwarmException::class, 'is unavailable for run');
});

test('legacy context schemas remain usable while native references require the additive migration', function () {
    Schema::create('legacy_native_contexts', function ($table): void {
        $table->string('run_id')->primary();
        $table->longText('input');
        $table->longText('data');
        $table->longText('metadata');
        $table->longText('artifacts');
        $table->timestamps();
        $table->timestamp('expires_at')->nullable();
    });
    config()->set('swarm.tables.contexts', 'legacy_native_contexts');

    $store = app(ContextStore::class);
    $store->put(RunContext::fromTask('legacy'), 60);

    $native = RunContext::fromTask('native')->setNativeInputReference('opaque-reference');
    expect(fn () => $store->put($native, 60))
        ->toThrow(SwarmException::class, 'Run migrations before enabling native inputs');
});

test('mutated promoted attachments fail identity checks and pruning removes owned files', function () {
    $context = RunContext::fromTask(new UserMessage('inspect', [new Base64Document(base64_encode('original'))]))
        ->withAgentInput(
            new UserMessage('inspect', [new Base64Document(base64_encode('original'))]),
            [NativeInputRecipient::parallel(0)],
        );
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);

    $fresh = RunContext::fromPayload($context->toQueuePayload());
    $invocation = $fresh->nativeInvocation('parallel:0', 'topology');
    /** @var StoredDocument $stored */
    $stored = $invocation->prompt->attachments->first();
    Storage::disk('native-inputs-test')->put($stored->path, 'mutated');

    $again = RunContext::fromPayload($context->toQueuePayload());
    expect(fn () => $again->nativeInvocation('parallel:0', 'topology'))
        ->toThrow(SwarmException::class, 'failed its content identity check');

    Storage::disk('native-inputs-test')->put($stored->path, 'original');
    DB::table('swarm_native_inputs')->where('id', $context->nativeInputReference())->update(['expires_at' => now()->subMinute()]);
    Artisan::call('swarm:prune');

    expect(DB::table('swarm_native_inputs')->where('id', $context->nativeInputReference())->exists())->toBeFalse()
        ->and(Storage::disk('native-inputs-test')->exists($stored->path))->toBeFalse();
});
