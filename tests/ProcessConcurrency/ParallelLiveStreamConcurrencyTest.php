<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmStreamProviderException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCitation;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\StreamEventIdentity;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelCitationStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelLiveStreamFailureSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelLiveStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ParallelProviderErrorStreamSwarm;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use RuntimeException;

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

test('parallel live stream emits before all branches finish and scopes reused native identities', function (): void {
    $path = parallelStreamPath('interleaving');

    try {
        $response = ParallelLiveStreamSwarm::make()->stream($path)->storeForReplay();
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
    } finally {
        removeParallelStreamMarkers($path);
    }
});

test('a failing branch preserves partial events and cancels a waiting sibling', function (): void {
    $path = parallelStreamPath('failure');
    $response = ParallelLiveStreamFailureSwarm::make()->stream($path);

    try {
        iterator_to_array($response, false);
        $this->fail('Expected the failing branch exception.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('parallel branch failed after a partial event');
    }

    usleep(300_000);
    $events = $response->events->all();
    expect(array_filter($events, fn ($event) => $event instanceof SwarmTextDelta))->not->toBeEmpty()
        ->and(array_filter($events, fn ($event) => $event instanceof SwarmStreamError))->toHaveCount(1)
        ->and(array_filter($events, fn ($event) => $event instanceof SwarmStepEnd))->toBeEmpty()
        ->and(file_exists($path.'.waiting-complete'))->toBeFalse();

    if (function_exists('posix_kill') && file_exists($path.'.waiting-pid')) {
        $pid = (int) file_get_contents($path.'.waiting-pid');
        expect(@posix_kill($pid, 0))->toBeFalse();
    }

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
    $response = ParallelLiveStreamSwarm::make()->stream($path);

    $response->each(fn ($event) => $event instanceof SwarmTextDelta ? false : null);
    usleep(300_000);

    foreach ([$path.'.branch-one-pid', $path.'.branch-two-pid'] as $pidFile) {
        if (function_exists('posix_kill') && file_exists($pidFile)) {
            expect(@posix_kill((int) file_get_contents($pidFile), 0))->toBeFalse();
        }
    }

    expect(file_exists($path.'.branch-two-complete'))->toBeFalse();
    removeParallelStreamMarkers($path);
});

test('the absolute deadline continues while a consumer holds an unacknowledged event', function (): void {
    config()->set('swarm.timeout', 1);
    $path = parallelStreamPath('deadline');
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
