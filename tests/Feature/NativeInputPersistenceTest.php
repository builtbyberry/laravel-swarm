<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\AuthorizesNativeInputAttachment;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceNativeInputDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastNativeInputSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeNativeInputSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeNativeInputQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManager;
use BuiltByBerry\LaravelSwarm\Support\NativeInputRecipient;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Testing\Audit\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksInputWhenMatches;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\GuardrailContainer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\StoredDocument;
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
        ->and($invocation->prompt->attachments->first())->toBeInstanceOf(Base64Document::class)
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
    'image' => [new Base64Image(base64_encode('native bytes'), 'image/png'), Base64Image::class],
    'document' => [new Base64Document(base64_encode('native bytes'), 'text/plain'), Base64Document::class],
    'audio' => [new Base64Audio(base64_encode('native bytes'), 'audio/mpeg'), Base64Audio::class],
    'video' => [new Base64Video(base64_encode('native bytes'), 'video/mp4'), Base64Video::class],
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
    $sealed = $cipher->seal($encoded);
    DB::table('swarm_native_inputs')->where('id', $reference)->update([
        'payload' => $sealed,
        'payload_hash' => hash('sha256', (string) $sealed),
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

test('disabling writers still allows admitted native work to drain', function () {
    $context = RunContext::fromTask(new UserMessage('already admitted'))->withAgentInput(
        new UserMessage('already admitted', [new Base64Document(base64_encode('drain me'), 'text/plain')]),
        [NativeInputRecipient::parallel(0, attachments: [0])],
    );
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);

    config()->set('swarm.native_inputs.enabled', false);
    $fresh = RunContext::fromPayload($context->toQueuePayload());
    app(NativeInputManager::class)->admit($fresh, Topology::Parallel, ExecutionMode::Queue);

    $prompt = $fresh->nativeInvocation('parallel:0', 'topology')->prompt;

    expect($prompt)->toBeInstanceOf(UserMessage::class)
        ->and($prompt->content)->toBe('topology')
        ->and($prompt->attachments->first()->content())->toBe('drain me');
});

test('an already hydrated context rechecks authoritative revocation', function () {
    $context = RunContext::fromTask(new UserMessage('authorized'));
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $fresh = RunContext::fromPayload($context->toQueuePayload());

    $fresh->nativeInvocation('parallel:0', 'topology');
    app(NativeInputStore::class)->revoke((string) $context->nativeInputReference(), $context->runId);

    expect(fn () => $fresh->nativeInvocation('parallel:0', 'topology'))
        ->toThrow(SwarmException::class, 'missing, revoked');
});

test('operational envelopes reject plaintext even when its digest is recomputed', function () {
    $context = RunContext::fromTask(new UserMessage('secret'));
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $reference = (string) $context->nativeInputReference();
    $row = DB::table('swarm_native_inputs')->where('id', $reference)->first();
    $plain = (string) app(SwarmPersistenceCipher::class)->openStrict($row->payload);

    DB::table('swarm_native_inputs')->where('id', $reference)->update([
        'payload' => $plain,
        'payload_hash' => hash('sha256', $plain),
    ]);

    expect(fn () => RunContext::fromPayload($context->toQueuePayload())->nativeInvocation('parallel:0', 'topology'))
        ->toThrow(SwarmException::class, 'sealed content identity check');
});

test('malformed queue references fail before a placeholder can execute', function () {
    $payload = RunContext::fromTask('legacy')->toQueuePayload();
    $payload['native_input_ref'] = ['not-a-reference'];

    expect(fn () => RunContext::fromPayload($payload))
        ->toThrow(SwarmException::class, 'non-empty string when present');
});

test('configured-disk attachments are bounded before admission', function () {
    Storage::disk('native-inputs-test')->put('large.pdf', 'too-large');
    config()->set('swarm.native_inputs.max_attachment_bytes', 2);
    app()->instance(AuthorizesNativeInputAttachment::class, new class implements AuthorizesNativeInputAttachment
    {
        public function authorize(File $attachment, RunContext $context): bool
        {
            return true;
        }
    });
    app()->forgetInstance(NativeInputManager::class);
    $message = new UserMessage('inspect', [new StoredDocument('large.pdf', 'native-inputs-test')]);
    $context = RunContext::fromTask($message)->withAgentInput($message, [NativeInputRecipient::parallel(0)]);

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'exceeds the configured [2] byte limit');
});

test('external stored references require an application ownership decision', function () {
    Storage::disk('native-inputs-test')->put('tenant.pdf', 'allowed-size');
    $message = new UserMessage('inspect', [new StoredDocument('tenant.pdf', 'native-inputs-test')]);
    $context = RunContext::fromTask($message)->withAgentInput($message, [NativeInputRecipient::parallel(0)]);

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'not authorized for this actor or tenant');
});

test('promotion preserves attachment identity across reconstruction', function () {
    $attachment = (new Base64Document(base64_encode('pdf-bytes'), 'application/pdf'))->as('contract.pdf');
    $message = new UserMessage('inspect', [$attachment]);
    $context = RunContext::fromTask($message)->withAgentInput($message, [NativeInputRecipient::parallel(0)]);
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);

    $restored = RunContext::fromPayload($context->toQueuePayload())
        ->nativeInvocation('parallel:0', 'topology')->prompt->attachments->first();

    expect($restored->name())->toBe('contract.pdf')
        ->and($restored->mimeType())->toBe('application/pdf')
        ->and($restored->content())->toBe('pdf-bytes');
});

test('recoverable reconstruction preserves plain and provider-resolved attachment invocation options', function () {
    $attachment = (new Base64Document(base64_encode('pdf-bytes'), 'application/pdf'))
        ->as('contract.pdf')
        ->withHeaders(fn (string $provider): array => ['X-Provider' => $provider])
        ->withProviderOptions(fn (string $provider): array => ['provider' => $provider, 'quality' => 'high']);
    $message = new UserMessage('inspect', [$attachment]);
    $context = RunContext::fromTask($message)->withAgentInput(
        $message,
        [NativeInputRecipient::parallel(0)->withInvocation('openai')],
    );

    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $restored = RunContext::fromPayload($context->toQueuePayload())
        ->nativeInvocation('parallel:0', 'topology')->prompt->attachments->first();

    expect($restored->headers('openai'))->toBe(['X-Provider' => 'openai'])
        ->and($restored->providerOptions('openai'))->toBe(['provider' => 'openai', 'quality' => 'high']);
});

test('provider-dependent attachment options require an explicit recoverable recipient provider', function () {
    $attachment = (new Base64Document(base64_encode('pdf-bytes')))
        ->withProviderOptions(fn (string $provider): array => ['provider' => $provider]);
    $message = new UserMessage('inspect', [$attachment]);
    $context = RunContext::fromTask($message)->withAgentInput($message, [NativeInputRecipient::parallel(0)]);

    expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'must declare one provider explicitly');
});

test('recoverable admission refuses an outer database transaction before file promotion', function () {
    $message = new UserMessage('inspect', [new Base64Document(base64_encode('secret'))]);
    $context = RunContext::fromTask($message)->withAgentInput($message, [NativeInputRecipient::parallel(0)]);

    DB::beginTransaction();
    try {
        expect(fn () => app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue))
            ->toThrow(SwarmException::class, 'outside an open database transaction');
    } finally {
        DB::rollBack();
    }

    expect(Storage::disk('native-inputs-test')->allFiles('swarm/native-inputs/'.$context->runId))->toBeEmpty();
});

test('the sealed staged envelope exists before the first promoted byte is written', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturnUsing(function (): bool {
        $row = DB::table('swarm_native_inputs')->first();

        expect($row)->not->toBeNull()
            ->and($row->state)->toBe('staged')
            ->and($row->payload)->toStartWith(SwarmPersistenceCipher::PREFIX);

        return true;
    });
    $filesystems = Mockery::mock(FilesystemFactory::class);
    $filesystems->shouldReceive('disk')->with('native-inputs-test')->andReturn($disk);
    $manager = new NativeInputManager(
        config(),
        app(NativeInputStore::class),
        $filesystems,
        app(AuthorizesNativeInputAttachment::class),
        app(SwarmAuditDispatcher::class),
    );
    $message = new UserMessage('inspect', [new Base64Document(base64_encode('secret'))]);
    $context = RunContext::fromTask($message)->withAgentInput($message, [NativeInputRecipient::parallel(0)]);

    $manager->admit($context, Topology::Parallel, ExecutionMode::Queue);

    expect(DB::table('swarm_native_inputs')->where('id', $context->nativeInputReference())->value('state'))->toBe('active');
});

test('failed promotion retains a revoked sealed locator for prune recovery', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    $disk->shouldReceive('exists')->once()->andReturnFalse();
    $filesystems = Mockery::mock(FilesystemFactory::class);
    $filesystems->shouldReceive('disk')->with('native-inputs-test')->andReturn($disk);
    $manager = new NativeInputManager(
        config(),
        app(NativeInputStore::class),
        $filesystems,
        app(AuthorizesNativeInputAttachment::class),
        app(SwarmAuditDispatcher::class),
    );
    $message = new UserMessage('inspect', [new Base64Document(base64_encode('secret'))]);
    $context = RunContext::fromTask($message)->withAgentInput($message, [NativeInputRecipient::parallel(0)]);

    expect(fn () => $manager->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'could not be promoted');

    $row = DB::table('swarm_native_inputs')->first();
    $payload = json_decode((string) app(SwarmPersistenceCipher::class)->openStrict($row->payload), true, 512, JSON_THROW_ON_ERROR);
    expect($row->state)->toBe('revoked')
        ->and($payload['attachments'][0]['swarm_owned'])->toBeTrue()
        ->and($payload['attachments'][0]['path'])->toStartWith('swarm/native-inputs/'.$context->runId.'/');
});

test('native capability marker jobs default to dispatch after commit', function () {
    Bus::fake();
    $queued = FakeSequentialSwarm::make()->queue(new UserMessage('queue'));
    $broadcast = FakeSequentialSwarm::make()->broadcastOnQueue(new UserMessage('broadcast'), new Channel('native'));
    $durable = FakeSequentialSwarm::make()->dispatchDurable(new UserMessage('durable'));
    $dispatcher = app(DurableJobDispatcher::class);

    expect($queued->getJob())->toBeInstanceOf(InvokeNativeInputSwarm::class)
        ->and($queued->getJob()->afterCommit)->toBeTrue()
        ->and($broadcast->getJob())->toBeInstanceOf(BroadcastNativeInputSwarm::class)
        ->and($broadcast->getJob()->afterCommit)->toBeTrue()
        ->and($durable->getJob())->toBeInstanceOf(AdvanceNativeInputDurableSwarm::class)
        ->and($durable->getJob()->afterCommit)->toBeTrue()
        ->and($dispatcher->makeBranchJob($durable->runId, 'branch'))->toBeInstanceOf(AdvanceNativeInputDurableBranch::class)
        ->and($dispatcher->makeBranchJob($durable->runId, 'branch')->afterCommit)->toBeTrue()
        ->and($dispatcher->makeQueuedResumeJob($durable->runId)->afterCommit)->toBeTrue();
});

test('failed envelope persistence compensates promoted files', function () {
    $store = new class implements NativeInputStore
    {
        public function put(string $id, string $runId, array $payload, string $hash, int $expiresAt): void
        {
            throw new RuntimeException('database unavailable');
        }

        public function find(string $id): ?array
        {
            return null;
        }

        public function activate(string $id, string $runId): void {}

        public function revoke(string $id, string $runId): void {}
    };
    $manager = new NativeInputManager(
        config(),
        $store,
        app(FilesystemFactory::class),
        app(AuthorizesNativeInputAttachment::class),
        app(SwarmAuditDispatcher::class),
    );
    $message = new UserMessage('inspect', [new Base64Document(base64_encode('secret'))]);
    $context = RunContext::fromTask($message)->withAgentInput($message, [NativeInputRecipient::parallel(0)]);

    expect(fn () => $manager->admit($context, Topology::Parallel, ExecutionMode::Queue))
        ->toThrow(RuntimeException::class, 'database unavailable');
    expect(Storage::disk('native-inputs-test')->allFiles('swarm/native-inputs/'.$context->runId))->toBeEmpty();
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
    'content hash' => ['hash', 'failed its sealed content identity check'],
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

    $row = DB::table('swarm_native_inputs')->where('id', $context->nativeInputReference())->first();
    $payload = json_decode((string) app(SwarmPersistenceCipher::class)->openStrict($row->payload), true, 512, JSON_THROW_ON_ERROR);
    $path = $payload['attachments'][0]['path'];
    Storage::disk('native-inputs-test')->put($path, 'mutated');

    $again = RunContext::fromPayload($context->toQueuePayload());
    expect(fn () => $again->nativeInvocation('parallel:0', 'topology'))
        ->toThrow(SwarmException::class, 'failed its content identity check');

    Storage::disk('native-inputs-test')->put($path, 'original');
    DB::table('swarm_native_inputs')->where('id', $context->nativeInputReference())->update(['expires_at' => now()->subMinute()]);
    Artisan::call('swarm:prune');

    expect(DB::table('swarm_native_inputs')->where('id', $context->nativeInputReference())->exists())->toBeFalse()
        ->and(Storage::disk('native-inputs-test')->exists($path))->toBeFalse();
});

test('native attachment release emits capture-safe audit evidence', function () {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);
    app()->forgetInstance(NativeInputManager::class);

    $message = new UserMessage('never audit this', [new Base64Document(base64_encode('nor this'), 'text/plain')]);
    $context = RunContext::fromTask($message)->withAgentInput(
        $message,
        [NativeInputRecipient::parallel(0, attachments: [0])->withInvocation('openai', 'gpt-test', 15)],
    );
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    RunContext::fromPayload($context->toQueuePayload())->nativeInvocation('parallel:0', 'topology');

    $record = $sink->recordsFor('native_input.released')[0] ?? [];
    expect($record)->toMatchArray([
        'run_id' => $context->runId,
        'recipient' => 'parallel:0',
        'attachment_count' => 1,
        'provider_override' => true,
        'model_override' => true,
        'timeout_override' => true,
    ])->and($record)->not->toHaveKey('native_input_ref')
        ->and(json_encode($record))->not->toContain('never audit this', 'nor this');
});

test('prune removes an expired envelope whose planned owned file was never written', function () {
    $context = RunContext::fromTask(new UserMessage('inspect', [new Base64Document(base64_encode('original'))]))
        ->withAgentInput(
            new UserMessage('inspect', [new Base64Document(base64_encode('original'))]),
            [NativeInputRecipient::parallel(0)],
        );
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
    $reference = (string) $context->nativeInputReference();
    $path = Storage::disk('native-inputs-test')->allFiles('swarm/native-inputs/'.$context->runId)[0];
    Storage::disk('native-inputs-test')->delete($path);
    DB::table('swarm_native_inputs')->where('id', $reference)->update(['expires_at' => now()->subMinute()]);

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_native_inputs')->where('id', $reference)->exists())->toBeFalse();
});

test('native queue guardrail rejection revokes its envelope and removes promoted files', function () {
    config()->set('swarm.guardrails.input', [BlocksInputWhenMatches::class]);
    app()->bind(BlocksInputWhenMatches::class, fn () => new BlocksInputWhenMatches('reject-native'));
    GuardrailContainer::refresh(app());
    app()->forgetInstance(SwarmRunner::class);

    $message = new UserMessage('reject-native', [new Base64Document(base64_encode('secret'))]);

    expect(fn () => FakeSequentialSwarm::make()->queue($message))
        ->toThrow(GuardrailViolation::class);

    expect(DB::table('swarm_native_inputs')->where('state', 'active')->count())->toBe(0)
        ->and(Storage::disk('native-inputs-test')->allFiles('swarm/native-inputs'))->toBeEmpty();
});

test('unsupported live parallel streaming rejects before native admission', function () {
    $message = new UserMessage('inspect', [new Base64Document(base64_encode('secret'))]);

    expect(fn () => FakeParallelSwarm::make()->stream($message))
        ->toThrow(SwarmException::class, 'Parallel live multiplexing is default-off');

    expect(DB::table('swarm_native_inputs')->count())->toBe(0)
        ->and(Storage::disk('native-inputs-test')->allFiles('swarm/native-inputs'))->toBeEmpty();
});

test('native prune isolates poisoned envelopes and keeps processing later rows', function () {
    $makeExpired = function (string $content): RunContext {
        $message = new UserMessage('inspect', [new Base64Document(base64_encode($content))]);
        $context = RunContext::fromTask($message)->withAgentInput(
            $message,
            [NativeInputRecipient::parallel(0, attachments: [0])],
        );
        app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);
        DB::table('swarm_native_inputs')->where('id', $context->nativeInputReference())->update(['expires_at' => now()->subMinute()]);

        return $context;
    };

    $poisoned = $makeExpired('poisoned');
    $healthy = $makeExpired('healthy');
    DB::table('swarm_native_inputs')->where('id', $poisoned->nativeInputReference())->update(['payload' => 'not-sealed']);

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_native_inputs')->where('id', $poisoned->nativeInputReference())->exists())->toBeTrue()
        ->and(DB::table('swarm_native_inputs')->where('id', $healthy->nativeInputReference())->exists())->toBeFalse();
});

test('native input migration is retry-safe after a partial application', function () {
    config()->set('swarm.tables.native_inputs', 'partial_native_inputs');
    config()->set('swarm.tables.contexts', 'partial_native_contexts');

    Schema::create('partial_native_inputs', function ($table): void {
        $table->string('id')->primary();
        $table->string('run_id')->index();
        $table->unsignedSmallInteger('format_version');
        $table->string('state');
        $table->longText('payload');
        $table->string('payload_hash', 64);
        $table->timestamp('expires_at')->index();
        $table->timestamps();
    });
    Schema::create('partial_native_contexts', function ($table): void {
        $table->string('run_id')->primary();
    });

    $migration = require __DIR__.'/../../database/migrations/2026_09_24_000001_create_swarm_native_inputs_table.php';
    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('partial_native_contexts', 'native_input_ref'))->toBeTrue();

    $migration->down();
    $migration->down();

    expect(Schema::hasTable('partial_native_inputs'))->toBeFalse()
        ->and(Schema::hasColumn('partial_native_contexts', 'native_input_ref'))->toBeFalse();
});
