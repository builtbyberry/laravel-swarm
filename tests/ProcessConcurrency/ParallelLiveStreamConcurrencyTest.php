<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\AuditSinkHaltedException;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmStreamProviderException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\ParallelStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCitation;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Parallel\ParallelProcessStreamTransport;
use BuiltByBerry\LaravelSwarm\Streaming\Parallel\ParallelStreamSession;
use BuiltByBerry\LaravelSwarm\Streaming\StreamEventIdentity;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\ParallelStreamBootstrapFailureWorker;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelCitationStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelCompletedSiblingFailureSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelContextBootstrapSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelLiveStreamAggregateOversizedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelLiveStreamFailureSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelLiveStreamGuardedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelLiveStreamOversizedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelLiveStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelProviderErrorStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingAuditCapturePolicy;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Event;
use Psr\Log\LoggerInterface;

pest()->group('process-concurrency');

beforeEach(function (): void {
    config()->set('concurrency.default', 'process');
    config()->set('swarm.streaming.parallel.enabled', true);
    config()->set('swarm.streaming.replay.enabled', true);
});

function parallelStreamPath(string $label): string
{
    return sys_get_temp_dir().'/laravel-swarm-'.$label.'-'.getmypid().'-'.bin2hex(random_bytes(4));
}

function removeParallelStreamMarkers(string $path): void
{
    foreach (glob($path.'.*') ?: [] as $marker) {
        @unlink($marker);
    }
}

function waitForParallelStreamProcessExit(string $pidFile, int $timeoutMilliseconds = 2_000): bool
{
    if (! function_exists('posix_kill')) {
        return true;
    }

    $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
    do {
        if (file_exists($pidFile) && ! @posix_kill((int) file_get_contents($pidFile), 0)) {
            return true;
        }
        usleep(20_000);
    } while (hrtime(true) < $deadline);

    return false;
}

test('pre-handshake child failure includes a bounded operational diagnostic', function (): void {
    $session = app(ParallelProcessStreamTransport::class)->start([
        'parallel:0' => ParallelStreamBootstrapFailureWorker::closure(),
    ], (float) hrtime(true) + 5_000_000_000, 65_536, 250);

    try {
        iterator_to_array($session->events(), false);
        $this->fail('Expected the bootstrap worker to fail.');
    } catch (SwarmException $exception) {
        expect($exception->getMessage())
            ->toMatch('/exit \d+; diagnostic withheld \(sha256:[a-f0-9]{64}\)/')
            ->not->toContain('provider-free bootstrap exploded')
            ->not->toContain('SENSITIVE-DIAGNOSTIC')
            ->not->toContain('TAIL-MARKER')
            ->not->toContain("\0");
        expect(strlen($exception->getMessage()))->toBeLessThan(300);
    }
});

test('unauthenticated and stalled loopback peers cannot terminate a legitimate branch', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    expect($server)->toBeResource();
    stream_set_blocking($server, false);
    $endpoint = stream_socket_get_name($server, false);
    $token = bin2hex(random_bytes(32));
    $code = <<<'PHP'
$endpoint = $argv[1]; $token = $argv[2];
$stalled = stream_socket_client('tcp://'.$endpoint);
$bad = stream_socket_client('tcp://'.$endpoint);
$badPayload = json_encode(['v' => 1, 'token' => 'wrong', 'branch_id' => 'parallel:0', 'type' => 'hello', 'payload' => []]);
fwrite($bad, pack('N', strlen($badPayload)).$badPayload); fclose($bad);
$valid = stream_socket_client('tcp://'.$endpoint);
$send = static function ($socket, array $frame): void { $payload = json_encode($frame); fwrite($socket, pack('N', strlen($payload)).$payload); if (fread($socket, 1) !== "\x06") { exit(2); } };
$send($valid, ['v' => 1, 'token' => $token, 'branch_id' => 'parallel:0', 'type' => 'hello', 'payload' => []]);
$send($valid, ['v' => 1, 'token' => $token, 'branch_id' => 'parallel:0', 'type' => 'terminal', 'payload' => ['ok' => true]]);
fclose($valid); fclose($stalled);
PHP;
    $peer = app(ProcessFactory::class)->newPendingProcess()
        ->command([PHP_BINARY, '-r', $code, $endpoint, $token])
        ->start();
    $session = new ParallelStreamSession(
        server: $server,
        processes: [],
        branchIds: ['parallel:0'],
        token: $token,
        maxFrameBytes: 65_536,
        deadline: (float) hrtime(true) + 5_000_000_000,
        cancelGraceMilliseconds: 250,
    );

    $events = $session->events();
    expect(iterator_to_array($events, false))->toHaveCount(1)
        ->and($events->getReturn()['parallel:0']['ok'])->toBeTrue()
        ->and($peer->wait()->successful())->toBeTrue();
});

test('pending handshake capacity admits every configured branch above the former fixed ceiling', function (): void {
    $branchCount = 72;
    $readyPath = parallelStreamPath('handshake-capacity-ready');
    $server = stream_socket_server(
        'tcp://127.0.0.1:0',
        context: stream_context_create(['socket' => ['backlog' => 256]]),
    );
    expect($server)->toBeResource();
    stream_set_blocking($server, false);
    $endpoint = stream_socket_get_name($server, false);
    $token = bin2hex(random_bytes(32));
    $code = <<<'PHP'
$endpoint = $argv[1]; $token = $argv[2]; $count = (int) $argv[3]; $readyPath = $argv[4]; $sockets = [];
$frame = static function (array $value): string { $payload = json_encode($value); return pack('N', strlen($payload)).$payload; };
for ($i = 0; $i < $count; $i++) {
    $socket = stream_socket_client('tcp://'.$endpoint);
    if (!is_resource($socket)) { fwrite(STDERR, 'connect failed: parallel:'.$i); exit(1); }
    $branch = 'parallel:'.$i;
    fwrite($socket, $frame(['v' => 1, 'token' => $token, 'branch_id' => $branch, 'type' => 'hello', 'payload' => []]));
    $sockets[$branch] = $socket;
}
file_put_contents($readyPath, 'ready');
foreach ($sockets as $branch => $socket) {
    if (fread($socket, 1) !== "\x06") { fwrite(STDERR, 'hello ack failed: '.$branch); exit(2); }
    fwrite($socket, $frame(['v' => 1, 'token' => $token, 'branch_id' => $branch, 'type' => 'terminal', 'payload' => ['ok' => true]]));
}
foreach ($sockets as $socket) {
    if (fread($socket, 1) !== "\x06") { exit(3); }
    fclose($socket);
}
PHP;
    $peer = app(ProcessFactory::class)->newPendingProcess()
        ->command([PHP_BINARY, '-r', $code, $endpoint, $token, (string) $branchCount, $readyPath])
        ->start();
    $readyDeadline = hrtime(true) + 2_000_000_000;
    while (! file_exists($readyPath) && hrtime(true) < $readyDeadline) {
        usleep(1_000);
    }
    expect(file_exists($readyPath))->toBeTrue();
    $branchIds = array_map(static fn (int $index): string => 'parallel:'.$index, range(0, $branchCount - 1));
    $session = new ParallelStreamSession(
        server: $server,
        processes: [],
        branchIds: $branchIds,
        token: $token,
        maxFrameBytes: 65_536,
        deadline: (float) hrtime(true) + 10_000_000_000,
        cancelGraceMilliseconds: 250,
    );

    try {
        $events = $session->events();
        $rows = iterator_to_array($events, false);
        expect($rows)->toHaveCount($branchCount)
            ->and($events->getReturn())->toHaveCount($branchCount)
            ->and($peer->wait()->successful())->toBeTrue();
    } finally {
        @unlink($readyPath);
    }
});

test('process workers enter run context before agent resolution and never resolve parent persistence', function (): void {
    expect(ParallelContextBootstrapSwarm::make()->prompt('buffered')->output)->toBe('context-bootstrap');

    $response = ParallelContextBootstrapSwarm::make()->stream('live');
    iterator_to_array($response, false);
    expect($response->streamedResponse?->output)->toBe('context-bootstrap');
});

test('post-history startup failure terminalizes the run before branch processes start', function (): void {
    $path = parallelStreamPath('startup-failure');
    $failed = null;
    Event::listen(SwarmStarted::class, static fn () => throw new RuntimeException('startup coordination failed'));
    Event::listen(SwarmFailed::class, function (SwarmFailed $event) use (&$failed): void {
        $failed = $event;
    });
    $context = RunContext::from($path, 'p7-live-startup-failure-'.getmypid());
    $response = ParallelLiveStreamSwarm::make()->stream($context);

    expect(fn () => iterator_to_array($response, false))
        ->toThrow(RuntimeException::class, 'startup coordination failed');
    expect(app(RunHistoryStore::class)->find($context->runId)['status'])->toBe('failed')
        ->and($failed)->toBeInstanceOf(SwarmFailed::class)
        ->and(glob($path.'.*') ?: [])->toBeEmpty();
});

test('parallel live stream emits before all branches finish and scopes reused native identities', function (): void {
    $path = parallelStreamPath('interleaving');
    file_put_contents($path.'.hold-branch-two', 'yes');
    Event::fake([SwarmCompleted::class, SwarmFailed::class]);
    $telemetrySink = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $telemetrySink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);
    $context = RunContext::from($path, 'p7-live-success-'.getmypid());

    try {
        $response = ParallelLiveStreamSwarm::make()->stream($context)->storeForReplay();
        $iterator = $response->getIterator();
        $iterator->rewind();
        $events = [];

        while ($iterator->valid() && ! $iterator->current() instanceof SwarmTextDelta) {
            $events[] = $iterator->current();
            $iterator->next();
        }

        $first = $iterator->current();
        expect($first)->toBeInstanceOf(SwarmTextDelta::class)
            ->and($first->delta)->toBe('branch-one')
            ->and(file_exists($path.'.branch-one-advanced'))->toBeFalse()
            ->and(file_exists($path.'.branch-two-complete'))->toBeFalse();

        file_put_contents($path.'.release-branch-two', 'yes');

        while ($iterator->valid()) {
            $events[] = $iterator->current();
            $iterator->next();
        }

        $deltas = array_values(array_filter($events, fn ($event) => $event instanceof SwarmTextDelta));
        expect($deltas)->toHaveCount(2)
            ->and(array_column(array_map(fn ($event) => $event->toArray(), $deltas), 'id'))
            ->toBe(['shared-native-event', 'shared-native-event'])
            ->and(array_column(array_map(fn ($event) => $event->toArray(), $deltas), 'invocation_id'))
            ->toBe(['shared-native-invocation', 'shared-native-invocation'])
            ->and(array_column(array_map(fn ($event) => $event->toArray(), $deltas), 'branch_id'))
            ->toBe(['parallel:0', 'parallel:1'])
            ->and($deltas[0]->attemptId)->not->toBe($deltas[1]->attemptId)
            ->and(array_map(fn ($event) => $event->branchSequence, $deltas))->toBe([1, 1])
            ->and($response->streamedResponse?->output)->toBe("branch-one\n\nbranch-two")
            ->and($response->streamedResponse?->usage)->toBe([
                'input_tokens' => 7,
                'output_tokens' => 10,
                'cache_read_input_tokens' => null,
                'cache_write_input_tokens' => null,
                'reasoning_tokens' => null,
            ])
            ->and(file_exists($path.'.branch-two-complete'))->toBeTrue();

        $replayed = iterator_to_array(app(StreamEventStore::class)->events($response->runId), false);
        $replayedDeltas = array_values(array_filter($replayed, fn ($event) => $event instanceof SwarmTextDelta));
        expect(array_map(fn ($event) => [$event->branchId, $event->attemptId, $event->branchSequence], $replayedDeltas))
            ->toBe(array_map(fn ($event) => [$event->branchId, $event->attemptId, $event->branchSequence], $deltas));
        expect(array_unique(array_map(StreamEventIdentity::forEvent(...), $replayedDeltas)))->toHaveCount(2);

        $history = app(RunHistoryStore::class)->find($context->runId);
        $storedContext = app(ContextStore::class)->find($context->runId);
        expect($history['status'])->toBe('completed')
            ->and($history['output'])->toBe("branch-one\n\nbranch-two")
            ->and($history['steps'])->toHaveCount(2)
            ->and($storedContext['data']['last_output'])->toBe("branch-one\n\nbranch-two");
        Event::assertDispatched(SwarmCompleted::class, fn (SwarmCompleted $event): bool => $event->runId === $context->runId);
        Event::assertNotDispatched(SwarmFailed::class);
        $branchTelemetry = collect($telemetrySink->recordsForCategory('stream.event'))
            ->where('event_type', 'swarm_text_delta')
            ->values();
        expect($branchTelemetry)->toHaveCount(2)
            ->and($branchTelemetry->pluck('branch_id')->sort()->values()->all())->toBe(['parallel:0', 'parallel:1'])
            ->and($branchTelemetry->every(fn (array $row): bool => is_string($row['attempt_id'] ?? null)
                && ($row['branch_sequence'] ?? null) === 1))->toBeTrue();
    } finally {
        removeParallelStreamMarkers($path);
    }
});

test('a failing branch preserves partial events and cancels a waiting sibling', function (): void {
    $path = parallelStreamPath('failure');
    Event::fake([SwarmCompleted::class, SwarmFailed::class]);
    $telemetrySink = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $telemetrySink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);
    $auditSink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $auditSink);
    app()->forgetInstance(SwarmAuditDispatcher::class);
    $context = RunContext::from($path, 'p7-live-failure-'.getmypid());
    $response = ParallelLiveStreamFailureSwarm::make()->stream($context);

    try {
        iterator_to_array($response, false);
        $this->fail('Expected the failing branch exception.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('parallel branch failed after a partial event');
    }

    $events = $response->events->all();
    expect(array_filter($events, fn ($event) => $event instanceof SwarmTextDelta))->not->toBeEmpty()
        ->and(array_filter($events, fn ($event) => $event instanceof SwarmStreamError))->toHaveCount(1)
        ->and(array_filter($events, fn ($event) => $event instanceof SwarmStepEnd))->toBeEmpty()
        ->and(file_exists($path.'.waiting-complete'))->toBeFalse();

    $error = collect($events)->first(fn ($event) => $event instanceof SwarmStreamError);
    $history = app(RunHistoryStore::class)->find($context->runId);
    expect(waitForParallelStreamProcessExit($path.'.waiting-pid'))->toBeTrue()
        ->and($history['status'])->toBe('failed')
        ->and($history['metadata']['usage']['input_tokens'])->toBe(11)
        ->and($history['metadata']['usage']['output_tokens'])->toBe(13)
        ->and($history['metadata']['branch_id'])->toBe($error->branchId)
        ->and($history['metadata']['attempt_id'])->toBe($error->attemptId)
        ->and($history['metadata']['branch_sequence'])->toBe($error->branchSequence);
    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $event): bool => $event->runId === $context->runId
        && $event->metadata['branch_id'] === $error->branchId
        && $event->metadata['attempt_id'] === $error->attemptId
        && $event->metadata['branch_sequence'] === $error->branchSequence);
    Event::assertNotDispatched(SwarmCompleted::class);
    $failureAudit = collect($auditSink->recordsForCategory('run.failed'))->first();
    expect($failureAudit['branch_id'])->toBe($error->branchId)
        ->and($failureAudit['attempt_id'])->toBe($error->attemptId)
        ->and($failureAudit['branch_sequence'])->toBe($error->branchSequence);
    $failureTelemetry = collect($telemetrySink->recordsForCategory('stream.event'))
        ->firstWhere('event_type', 'swarm_stream_error');
    expect($failureTelemetry)
        ->toBeArray()
        ->and($failureTelemetry['branch_id'])->toBe('parallel:0')
        ->and($failureTelemetry['attempt_id'])->toBeString()
        ->and($failureTelemetry['branch_sequence'])->toBeInt();

    removeParallelStreamMarkers($path);
});

test('an audit halt is not recursively emitted as run failed', function (): void {
    $sink = new class implements SwarmAuditSink
    {
        public array $categories = [];

        public function emit(string $category, array $payload): void
        {
            $this->categories[] = $category;
            throw new RuntimeException('halt audit');
        }
    };
    app()->instance(SwarmAuditSink::class, $sink);
    app()->forgetInstance(SwarmAuditDispatcher::class);
    config()->set('swarm.audit.failure_policy', 'halt');
    $path = parallelStreamPath('audit-halt');

    expect(fn () => iterator_to_array(ParallelLiveStreamSwarm::make()->stream($path), false))
        ->toThrow(AuditSinkHaltedException::class);
    expect($sink->categories)->toBe(['run.started']);
    removeParallelStreamMarkers($path);
});

test('a completed sibling keeps terminal cost and provenance evidence when another branch fails', function (): void {
    $path = parallelStreamPath('completed-sibling-failure');
    $context = RunContext::from($path, 'p7-completed-sibling-failure-'.getmypid());
    $response = ParallelCompletedSiblingFailureSwarm::make()->stream($context)->storeForReplay();
    $iterator = $response->getIterator();
    $iterator->rewind();

    while ($iterator->valid() && ! $iterator->current() instanceof SwarmStepEnd) {
        $iterator->next();
    }

    $completed = $iterator->current();
    expect($completed)->toBeInstanceOf(SwarmStepEnd::class)
        ->and($completed->branchId)->toBe('parallel:0')
        ->and($completed->metadata['usage']['input_tokens'])->toBe(2)
        ->and($completed->citationEvidence->items)->not->toBeEmpty()
        ->and($completed->nativeResult?->provider)->toBe('fixture');
    file_put_contents($path.'.release-failure', 'yes');

    expect(function () use ($iterator): void {
        while ($iterator->valid()) {
            $iterator->next();
        }
    })->toThrow(RuntimeException::class, 'parallel branch failed after sibling completion');

    $history = app(RunHistoryStore::class)->find($context->runId);
    $replayed = iterator_to_array(app(StreamEventStore::class)->events($context->runId), false);
    expect($history['status'])->toBe('failed')
        ->and($history['steps'])->toHaveCount(1)
        ->and($history['steps'][0]['metadata']['usage']['input_tokens'])->toBe(2)
        ->and($history['steps'][0]['citations'])->not->toBeEmpty()
        ->and($history['steps'][0]['native_result']['provider'])->toBe('fixture')
        ->and(array_values(array_filter($replayed, fn ($event) => $event instanceof SwarmStepEnd)))->toHaveCount(1)
        ->and(array_values(array_filter($replayed, fn ($event) => $event instanceof SwarmStreamEnd)))->toBeEmpty();
    removeParallelStreamMarkers($path);
});

test('a blocking step guardrail fails canonical history without a terminal event for the blocked branch', function (): void {
    $path = parallelStreamPath('step-guardrail');
    Event::fake([SwarmCompleted::class, SwarmFailed::class]);
    $telemetrySink = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $telemetrySink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);
    $auditSink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $auditSink);
    app()->forgetInstance(SwarmAuditDispatcher::class);
    $context = RunContext::from($path, 'p7-live-guardrail-'.getmypid());
    $response = ParallelLiveStreamGuardedSwarm::make()->stream($context);

    expect(fn () => iterator_to_array($response, false))
        ->toThrow(GuardrailViolation::class, 'step blocked');

    $stepEnds = $response->events->whereInstanceOf(SwarmStepEnd::class);
    $error = $response->events->whereInstanceOf(SwarmStreamError::class)->sole();
    $history = app(RunHistoryStore::class)->find($context->runId);
    foreach ($stepEnds as $stepEnd) {
        expect($stepEnd->branchId)->toBe('parallel:0');
    }

    expect($stepEnds->count())->toBeLessThanOrEqual(1)
        ->and($response->events->whereInstanceOf(SwarmStreamEnd::class))->toBeEmpty()
        ->and($response->events->whereInstanceOf(SwarmStreamError::class))->toHaveCount(1)
        ->and($error->branchId)->toBe('parallel:1')
        ->and($history['status'])->toBe('failed')
        ->and($history['metadata']['branch_id'])->toBe('parallel:1')
        ->and($history['metadata']['attempt_id'])->toBe($error->attemptId);
    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $event): bool => $event->runId === $context->runId
        && $event->metadata['branch_id'] === 'parallel:1'
        && $event->metadata['attempt_id'] === $error->attemptId);
    Event::assertNotDispatched(SwarmCompleted::class);
    expect(collect($auditSink->recordsForCategory('run.failed'))->sole()['branch_id'])->toBe('parallel:1')
        ->and(collect($telemetrySink->recordsForCategory('stream.event'))->firstWhere('event_type', 'swarm_stream_error')['branch_id'])
        ->toBe('parallel:1');
    removeParallelStreamMarkers($path);
});

test('an oversized branch frame fails history and reaps its waiting sibling', function (): void {
    config()->set('swarm.streaming.parallel.max_frame_bytes', 1_024);
    $path = parallelStreamPath('oversized');
    $context = RunContext::from($path, 'p7-live-oversized-'.getmypid());
    $response = ParallelLiveStreamOversizedSwarm::make()->stream($context);

    try {
        iterator_to_array($response, false);
        $this->fail('Expected the oversized branch frame to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('frame');
    }

    expect($response->events->whereInstanceOf(SwarmStreamError::class))->toHaveCount(1)
        ->and($response->events->whereInstanceOf(SwarmStepEnd::class))->toBeEmpty()
        ->and($response->events->whereInstanceOf(SwarmStreamEnd::class))->toBeEmpty()
        ->and(app(RunHistoryStore::class)->find($context->runId)['status'])->toBe('failed')
        ->and(waitForParallelStreamProcessExit($path.'.waiting-pid'))->toBeTrue();
    removeParallelStreamMarkers($path);
});

test('an aggregate terminal outcome above the frame bound fails history and reaps its waiting sibling', function (): void {
    config()->set('swarm.streaming.parallel.max_frame_bytes', 1_024);
    $path = parallelStreamPath('aggregate-oversized');
    $context = RunContext::from($path, 'p7-live-aggregate-oversized-'.getmypid());
    $response = ParallelLiveStreamAggregateOversizedSwarm::make()->stream($context);

    try {
        iterator_to_array($response, false);
        $this->fail('Expected the aggregate terminal outcome to exceed the frame bound.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('terminal outcome frames are atomic');
    }

    expect($response->events->whereInstanceOf(SwarmTextDelta::class))->toHaveCount(3)
        ->and($response->events->whereInstanceOf(SwarmStreamError::class))->toHaveCount(1)
        ->and($response->events->whereInstanceOf(SwarmStepEnd::class))->toBeEmpty()
        ->and($response->events->whereInstanceOf(SwarmStreamEnd::class))->toBeEmpty()
        ->and(app(RunHistoryStore::class)->find($context->runId)['status'])->toBe('failed')
        ->and(waitForParallelStreamProcessExit($path.'.waiting-pid'))->toBeTrue();
    removeParallelStreamMarkers($path);
});

test('a provider error keeps native error identity while gaining branch identity', function (): void {
    $path = parallelStreamPath('provider-failure');
    $response = ParallelProviderErrorStreamSwarm::make()->stream($path);

    try {
        iterator_to_array($response, false);
        $this->fail('Expected the provider stream exception.');
    } catch (SwarmStreamProviderException $exception) {
        expect($exception->getMessage())->toBe('Provider stream failed.');
    }

    $error = $response->events->whereInstanceOf(SwarmStreamError::class)->sole();
    expect($error->id)->toBe('provider-error-1')
        ->and($error->invocationId)->toBe('provider-invocation-1')
        ->and($error->recoverable)->toBeTrue()
        ->and($error->branchId)->toBe('parallel:0')
        ->and($error->attemptId)->not->toBeNull()
        ->and($error->branchSequence)->toBeInt()
        ->and($error->metadata['provider_error']['provider_error_type'])->toBe('provider_rate_limited');

    removeParallelStreamMarkers($path);
});

test('capture decisions citations usage and terminal native results cross the process boundary once', function (): void {
    $events = iterator_to_array(ParallelCitationStreamSwarm::make()->stream('citation-process-task'), false);
    $citations = array_values(array_filter($events, fn ($event) => $event instanceof SwarmCitation));
    $ends = array_values(array_filter($events, fn ($event) => $event instanceof SwarmStepEnd));
    $citationBranches = array_map(fn ($event) => $event->branchId, $citations);
    sort($citationBranches);

    expect($citations)->toHaveCount(2)
        ->and($citationBranches)->toBe(['parallel:0', 'parallel:1'])
        ->and($ends)->toHaveCount(2)
        ->and($ends[0]->nativeResult?->provider)->toBe('fixture')
        ->and($ends[1]->nativeResult?->provider)->toBe('fixture')
        ->and($ends[0]->metadata['usage']['input_tokens'])->toBe(2)
        ->and($ends[1]->metadata['usage']['input_tokens'])->toBe(2);

    config()->set('swarm.capture.outputs', false);
    $redactedPath = parallelStreamPath('redacted');
    try {
        $redacted = iterator_to_array(ParallelLiveStreamSwarm::make()->stream($redactedPath), false);
        $redactedDeltas = array_values(array_filter($redacted, fn ($event) => $event instanceof SwarmTextDelta));
        expect(array_map(fn ($event) => $event->delta, $redactedDeltas))->toBe(['[redacted]', '[redacted]']);
    } finally {
        removeParallelStreamMarkers($redactedPath);
    }
});

test('capture skip omits process-stream payloads while retaining branch correlation', function (): void {
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy);
    foreach ([
        SwarmCapture::class,
        ContextStore::class,
        RunHistoryStore::class,
        DatabaseContextStore::class,
        DatabaseRunHistoryStore::class,
        ParallelStreamRunner::class,
        SwarmRunner::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $path = parallelStreamPath('skip-sensitive');
    $context = RunContext::from($path, 'p7-live-skip-'.getmypid());
    try {
        $response = ParallelLiveStreamSwarm::make()->stream($context)->storeForReplay();
        $events = iterator_to_array($response, false);
        $replay = iterator_to_array(app(StreamEventStore::class)->events($context->runId), false);
        $history = app(RunHistoryStore::class)->find($context->runId);
        $storedContext = app(ContextStore::class)->find($context->runId);

        foreach ([$events, $replay] as $stream) {
            $payloads = array_map(fn ($event): array => $event->toArray(), $stream);
            $encoded = json_encode($payloads, JSON_THROW_ON_ERROR);
            expect($encoded)
                ->not->toContain($path)
                ->not->toContain('branch-one')
                ->not->toContain('branch-two')
                ->not->toContain('[redacted]');
            $branchPayloads = collect($payloads)->filter(fn (array $payload): bool => isset($payload['branch_id']));
            expect($branchPayloads)->not->toBeEmpty()
                ->and($branchPayloads->every(fn (array $payload): bool => is_string($payload['branch_id'])
                    && is_string($payload['attempt_id'])
                    && is_int($payload['branch_sequence'])))->toBeTrue();
        }

        expect($history['output'])->toBeNull()
            ->and($history['steps'][0])->not->toHaveKey('input')
            ->and($history['steps'][0])->not->toHaveKey('output');
        expect(json_encode([$history, $storedContext], JSON_THROW_ON_ERROR))
            ->not->toContain($path)
            ->not->toContain('branch-one')
            ->not->toContain('branch-two');
    } finally {
        removeParallelStreamMarkers($path);
    }
});

test('parallel branch identity survives immediate broadcast envelopes', function (): void {
    Event::fake([AnonymousEvent::class]);
    $path = parallelStreamPath('broadcast');

    try {
        ParallelLiveStreamSwarm::make()->broadcastNow($path, new Channel('swarm.parallel'));

        $deltas = Event::dispatched(AnonymousEvent::class)
            ->map(fn (array $event): AnonymousEvent => $event[0])
            ->filter(fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'swarm_text_delta')
            ->map(fn (AnonymousEvent $event): array => $event->broadcastWith())
            ->values();
        $branches = $deltas->pluck('branch_id')->sort()->values()->all();

        expect($deltas)->toHaveCount(2)
            ->and($branches)->toBe(['parallel:0', 'parallel:1'])
            ->and($deltas->every(fn (array $payload): bool => is_string($payload['attempt_id'] ?? null)
                && is_int($payload['branch_sequence'] ?? null)))->toBeTrue();
    } finally {
        removeParallelStreamMarkers($path);
    }
});

test('consumer abandonment terminates and reaps active branch processes', function (): void {
    $path = parallelStreamPath('abandon');
    file_put_contents($path.'.hold-branch-two', 'yes');
    file_put_contents($path.'.require-both-pids', 'yes');
    $delegate = app(ContextStore::class);
    app()->instance(ContextStore::class, new class($delegate) implements ContextStore
    {
        private int $writes = 0;

        public function __construct(private ContextStore $delegate) {}

        public function put(RunContext $context, int $ttlSeconds): void
        {
            if (++$this->writes > 1) {
                throw new RuntimeException('terminal context persistence failed');
            }

            $this->delegate->put($context, $ttlSeconds);
        }

        public function find(string $runId): ?array
        {
            return $this->delegate->find($runId);
        }
    });
    $logger = Mockery::spy(LoggerInterface::class);
    app()->instance(LoggerInterface::class, $logger);
    app()->forgetInstance(ParallelStreamRunner::class);
    app()->forgetInstance(SwarmRunner::class);
    $response = ParallelLiveStreamSwarm::make()->stream($path);

    $response->each(fn ($event) => $event instanceof SwarmTextDelta ? false : null);

    foreach ([$path.'.branch-one-pid', $path.'.branch-two-pid'] as $pidFile) {
        expect(waitForParallelStreamProcessExit($pidFile))->toBeTrue();
    }

    expect(file_exists($path.'.branch-two-complete'))->toBeFalse();
    $logger->shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'Parallel swarm stream abandonment could not be terminalized.'
            && $context['run_id'] === $response->runId
            && $context['exception_class'] === RuntimeException::class,
    );
    removeParallelStreamMarkers($path);
});

test('the absolute deadline continues while a consumer holds an unacknowledged event', function (): void {
    config()->set('swarm.timeout', 1);
    $path = parallelStreamPath('deadline');
    file_put_contents($path.'.hold-branch-two', 'yes');
    $iterator = ParallelLiveStreamSwarm::make()->stream($path)->getIterator();
    $iterator->rewind();

    while ($iterator->valid() && ! $iterator->current() instanceof SwarmTextDelta) {
        $iterator->next();
    }

    sleep(2);
    expect(function () use ($iterator): void {
        while ($iterator->valid()) {
            $iterator->next();
        }
    })->toThrow(SwarmTimeoutException::class);
    removeParallelStreamMarkers($path);
});
