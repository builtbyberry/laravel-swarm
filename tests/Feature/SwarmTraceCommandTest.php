<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\ReadableSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Exception\RuntimeException;

beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    app()->forgetInstance(AuditOutbox::class);
    app()->forgetInstance(SwarmAuditDispatcher::class);
});

/**
 * @param  array<int, array<string, mixed>>  $records
 */
function bindReadableSinkRecords(array $records): ReadableSwarmAuditSink
{
    $sink = new class($records) implements ReadableSwarmAuditSink
    {
        /**
         * @param  array<int, array<string, mixed>>  $records
         */
        public function __construct(private readonly array $records) {}

        public function emit(string $category, array $payload): void
        {
            // Recording is not needed for these tests — forRun() returns
            // pre-seeded records that simulate a sink with an external store.
        }

        public function forRun(string $runId): iterable
        {
            foreach ($this->records as $record) {
                if (($record['run_id'] ?? null) === $runId) {
                    yield $record;
                }
            }
        }
    };

    app()->instance(SwarmAuditSink::class, $sink);

    return $sink;
}

function bindUnreadableSink(): SwarmAuditSink
{
    $sink = new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void
        {
            // no-op recorder substitute — does NOT implement ReadableSwarmAuditSink.
        }
    };

    app()->instance(SwarmAuditSink::class, $sink);

    return $sink;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedTraceHistoryRow(string $runId, array $overrides = []): void
{
    $now = Carbon::now('UTC');
    $cipher = app(SwarmPersistenceCipher::class);

    DB::table('swarm_run_histories')->insert(array_merge([
        'run_id' => $runId,
        'swarm_class' => 'TraceTestSwarm',
        'topology' => 'sequential',
        'status' => 'completed',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([
            [
                'agent_class' => 'TraceAgent',
                'input' => $cipher->seal('hello'),
                'output' => $cipher->seal('world'),
                'artifacts' => [],
                'metadata' => ['index' => 0, 'recorded_at' => $now->copy()->addSecond()->toIso8601String()],
            ],
        ]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => $now,
        'finished_at' => $now->copy()->addSeconds(2),
        'updated_at' => $now,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedTraceOutboxRow(string $runId, array $overrides = []): int
{
    $payload = $overrides['payload'] ?? [
        'run_id' => $runId,
        'category' => 'run.failed',
        'occurred_at' => Carbon::now('UTC')->toIso8601String(),
        'exception_class' => 'RuntimeException',
    ];
    $cipher = app(SwarmPersistenceCipher::class);
    unset($overrides['payload']);

    return (int) DB::table('swarm_audit_outbox')->insertGetId(array_merge([
        'category' => $payload['category'] ?? 'run.failed',
        'run_id' => $runId,
        'payload' => $cipher->seal((string) json_encode($payload, JSON_THROW_ON_ERROR)),
        'attempts' => 2,
        'status' => 'pending',
        'last_error' => $cipher->seal('sink unavailable'),
        'last_attempted_at' => Carbon::now('UTC'),
        'reserved_at' => null,
        'created_at' => Carbon::now('UTC')->subMinute(),
        'updated_at' => Carbon::now('UTC'),
    ], $overrides));
}

// -----------------------------------------------------------------------------
// Argument handling
// -----------------------------------------------------------------------------

test('swarm:trace requires a run_id argument', function (): void {
    expect(fn () => Artisan::call('swarm:trace'))->toThrow(RuntimeException::class);
});

// -----------------------------------------------------------------------------
// Clean run trace — sink implements ReadableSwarmAuditSink
// -----------------------------------------------------------------------------

test('clean run with readable sink renders history + sink records merged chronologically', function (): void {
    $runId = 'r-clean';
    seedTraceHistoryRow($runId);

    bindReadableSinkRecords([
        [
            'run_id' => $runId,
            'category' => 'run.started',
            'occurred_at' => Carbon::now('UTC')->toIso8601String(),
            'payload' => ['run_id' => $runId, 'category' => 'run.started'],
        ],
        [
            'run_id' => $runId,
            'category' => 'run.completed',
            'occurred_at' => Carbon::now('UTC')->addSeconds(3)->toIso8601String(),
            'payload' => ['run_id' => $runId, 'category' => 'run.completed', 'duration_ms' => 2000],
        ],
        [
            'run_id' => 'other-run',
            'category' => 'run.started',
            'occurred_at' => Carbon::now('UTC')->toIso8601String(),
        ],
    ]);

    $exit = Artisan::call('swarm:trace', ['run_id' => $runId, '--json' => true]);

    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['ok'])->toBeTrue();
    expect($payload['run_id'])->toBe($runId);
    expect($payload['degraded'])->toBeFalse();
    expect($payload['sources']['sink']['readable'])->toBeTrue();
    expect($payload['sources']['sink']['record_count'])->toBe(2);
    expect($payload['sources']['history']['available'])->toBeTrue();
    expect($payload['sources']['outbox']['available'])->toBeTrue();
    expect($payload['notes'])->toBe([]);

    $categories = array_map(fn (array $r): string => (string) $r['category'], $payload['records']);
    expect($categories)->toContain('run.started');
    expect($categories)->toContain('run.completed');
    expect($categories)->toContain('history.started');
    expect($categories)->toContain('history.step');
    expect($categories)->toContain('history.finished');

    // Records are sorted by occurred_at — verify the sort actually happened.
    $occurredAt = array_values(array_filter(array_map(fn (array $r): ?string => $r['occurred_at'] ?? null, $payload['records'])));
    $sorted = $occurredAt;
    sort($sorted);
    expect($occurredAt)->toBe($sorted);
});

// -----------------------------------------------------------------------------
// Outbox failed/pending records
// -----------------------------------------------------------------------------

test('run with pending outbox rows surfaces them in the timeline with attempt counts', function (): void {
    $runId = 'r-failed';
    seedTraceHistoryRow($runId, ['status' => 'running', 'finished_at' => null]);
    seedTraceOutboxRow($runId, ['attempts' => 3, 'status' => 'pending']);
    bindUnreadableSink();

    Artisan::call('swarm:trace', ['run_id' => $runId, '--json' => true]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $outboxRows = array_values(array_filter(
        $payload['records'],
        fn (array $r): bool => ($r['source'] ?? '') === 'outbox',
    ));

    expect($outboxRows)->toHaveCount(1);
    expect($outboxRows[0]['status'])->toBe('pending');
    expect($outboxRows[0]['attempts'])->toBe(3);
    expect($outboxRows[0]['category'])->toBe('run.failed');
    expect($outboxRows[0]['last_error'])->toBe('sink unavailable');
});

// -----------------------------------------------------------------------------
// Dead-lettered run
// -----------------------------------------------------------------------------

test('dead-lettered outbox rows appear with status=dead_letter', function (): void {
    $runId = 'r-dlq';
    seedTraceHistoryRow($runId);
    seedTraceOutboxRow($runId, ['status' => 'dead_letter', 'attempts' => 5]);
    bindUnreadableSink();

    Artisan::call('swarm:trace', ['run_id' => $runId, '--json' => true]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $outboxRows = array_values(array_filter(
        $payload['records'],
        fn (array $r): bool => ($r['source'] ?? '') === 'outbox',
    ));

    expect($outboxRows)->toHaveCount(1);
    expect($outboxRows[0]['status'])->toBe('dead_letter');
    expect($outboxRows[0]['attempts'])->toBe(5);
});

// -----------------------------------------------------------------------------
// Cache-driver fallback — outbox unavailable
// -----------------------------------------------------------------------------

test('cache-driver fallback degrades gracefully with a clear note', function (): void {
    config()->set('swarm.persistence.driver', 'cache');
    app()->forgetInstance(AuditOutbox::class);

    bindReadableSinkRecords([]);

    $exit = Artisan::call('swarm:trace', ['run_id' => 'r-cache', '--json' => true]);

    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['sources']['outbox']['available'])->toBeFalse();
    expect($payload['sources']['outbox']['record_count'])->toBe(0);
    expect(implode("\n", $payload['notes']))->toContain('Audit outbox is unavailable');
});

// -----------------------------------------------------------------------------
// NoOpSwarmAuditSink — clear note about limitation
// -----------------------------------------------------------------------------

test('NoOpSwarmAuditSink surfaces a clear note that sink-side records are unavailable', function (): void {
    $runId = 'r-noop';
    seedTraceHistoryRow($runId);

    app()->instance(SwarmAuditSink::class, new NoOpSwarmAuditSink);
    app()->forgetInstance(SwarmAuditDispatcher::class);

    Artisan::call('swarm:trace', ['run_id' => $runId, '--json' => true]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['degraded'])->toBeTrue();
    expect($payload['sources']['sink']['readable'])->toBeFalse();
    expect($payload['sources']['sink']['reason'])->toBe('noop');
    expect(implode("\n", $payload['notes']))->toContain('NoOpSwarmAuditSink');
});

// -----------------------------------------------------------------------------
// Bound sink does not implement ReadableSwarmAuditSink — graceful degradation
// -----------------------------------------------------------------------------

test('non-readable sink degrades to outbox + history only with a clear note', function (): void {
    $runId = 'r-not-readable';
    seedTraceHistoryRow($runId);
    seedTraceOutboxRow($runId);

    bindUnreadableSink();

    Artisan::call('swarm:trace', ['run_id' => $runId, '--json' => true]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['degraded'])->toBeTrue();
    expect($payload['sources']['sink']['readable'])->toBeFalse();
    expect($payload['sources']['sink']['reason'])->toBe('not_readable');
    expect(implode("\n", $payload['notes']))->toContain('does not implement ReadableSwarmAuditSink');

    // outbox and history are still in the timeline.
    $sources = array_map(fn (array $r): string => (string) $r['source'], $payload['records']);
    expect($sources)->toContain('outbox');
    expect($sources)->toContain('history');
});

// -----------------------------------------------------------------------------
// Missing run history record
// -----------------------------------------------------------------------------

test('unknown run renders an empty trace with a note explaining nothing was found', function (): void {
    bindUnreadableSink();

    Artisan::call('swarm:trace', ['run_id' => 'r-missing', '--json' => true]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['sources']['history']['available'])->toBeFalse();
    expect(implode("\n", $payload['notes']))->toContain('No run history record found');
});

// -----------------------------------------------------------------------------
// --include-payloads toggles payload visibility
// -----------------------------------------------------------------------------

test('--include-payloads off omits payload field from each record', function (): void {
    $runId = 'r-payload-off';
    seedTraceHistoryRow($runId);
    seedTraceOutboxRow($runId);
    bindUnreadableSink();

    Artisan::call('swarm:trace', ['run_id' => $runId, '--json' => true]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    foreach ($payload['records'] as $record) {
        expect($record)->not->toHaveKey('payload');
    }

    expect($payload['include_payloads'])->toBeFalse();
});

test('--include-payloads on attaches the full envelope per record', function (): void {
    $runId = 'r-payload-on';
    seedTraceHistoryRow($runId);
    seedTraceOutboxRow($runId, ['payload' => [
        'run_id' => $runId,
        'category' => 'run.failed',
        'occurred_at' => Carbon::now('UTC')->toIso8601String(),
        'exception_class' => 'RuntimeException',
        'secret_marker' => 'xyz-123',
    ]]);
    bindReadableSinkRecords([
        [
            'run_id' => $runId,
            'category' => 'run.started',
            'occurred_at' => Carbon::now('UTC')->toIso8601String(),
            'payload' => ['run_id' => $runId, 'category' => 'run.started', 'sink_marker' => 'abc-456'],
        ],
    ]);

    Artisan::call('swarm:trace', [
        'run_id' => $runId,
        '--json' => true,
        '--include-payloads' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['include_payloads'])->toBeTrue();

    $outboxRow = null;
    $sinkRow = null;

    foreach ($payload['records'] as $record) {
        if ($outboxRow === null && ($record['source'] ?? '') === 'outbox') {
            $outboxRow = $record;
        }

        if ($sinkRow === null && ($record['source'] ?? '') === 'sink') {
            $sinkRow = $record;
        }
    }

    expect($outboxRow)->not->toBeNull();
    expect($outboxRow['payload']['secret_marker'])->toBe('xyz-123');

    expect($sinkRow)->not->toBeNull();
    expect($sinkRow['payload']['sink_marker'])->toBe('abc-456');
});

// -----------------------------------------------------------------------------
// Human-readable output
// -----------------------------------------------------------------------------

test('default human output renders a timeline table', function (): void {
    $runId = 'r-human';
    seedTraceHistoryRow($runId);
    seedTraceOutboxRow($runId);
    bindUnreadableSink();

    $exit = Artisan::call('swarm:trace', ['run_id' => $runId]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('Audit chain trace for run');
    expect($output)->toContain('Timeline');
    expect($output)->toContain('history.started');
    expect($output)->toContain('Occurred at');
});

// -----------------------------------------------------------------------------
// --limit guards unbounded sink reads (review F6)
// -----------------------------------------------------------------------------

test('--limit truncates sink-side records and surfaces a clear note', function (): void {
    $runId = 'r-truncated';
    seedTraceHistoryRow($runId);

    // Seed 5 sink records; --limit=3 should consume only the first three and
    // mark the result as truncated. Outbox + history rows are unaffected.
    $records = [];
    for ($i = 0; $i < 5; $i++) {
        $records[] = [
            'run_id' => $runId,
            'category' => 'run.started',
            'occurred_at' => Carbon::now('UTC')->addSeconds($i)->toIso8601String(),
            'payload' => ['run_id' => $runId, 'category' => 'run.started', 'seq' => $i],
        ];
    }
    bindReadableSinkRecords($records);

    $exit = Artisan::call('swarm:trace', [
        'run_id' => $runId,
        '--json' => true,
        '--limit' => 3,
    ]);

    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['sources']['sink']['record_count'])->toBe(3);
    expect($payload['sources']['sink']['limit'])->toBe(3);
    expect($payload['sources']['sink']['truncated'])->toBeTrue();
    expect($payload['notes'])->toContain(
        'Sink returned more than --limit=3 records; sink-side records were truncated. Pass a higher --limit if needed.'
    );
});

test('--limit not exceeded leaves truncated=false and no note', function (): void {
    $runId = 'r-within-limit';
    seedTraceHistoryRow($runId);

    bindReadableSinkRecords([
        [
            'run_id' => $runId,
            'category' => 'run.started',
            'occurred_at' => Carbon::now('UTC')->toIso8601String(),
            'payload' => ['run_id' => $runId, 'category' => 'run.started'],
        ],
    ]);

    $exit = Artisan::call('swarm:trace', [
        'run_id' => $runId,
        '--json' => true,
        '--limit' => 10,
    ]);

    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['sources']['sink']['truncated'])->toBeFalse();
    expect($payload['sources']['sink']['limit'])->toBe(10);
    foreach ($payload['notes'] as $note) {
        expect($note)->not->toContain('sink-side records were truncated');
    }
});

test('--limit=0 is rejected as invalid', function (): void {
    $exit = Artisan::call('swarm:trace', [
        'run_id' => 'r-bad-limit',
        '--limit' => 0,
    ]);

    expect($exit)->toBe(1);
});

// -----------------------------------------------------------------------------
// Sink-throw error differentiation (review F2)
// -----------------------------------------------------------------------------

test('sink throwing a RuntimeException surfaces in notes without crashing', function (): void {
    $runId = 'r-sink-runtime-error';
    seedTraceHistoryRow($runId);

    $sink = new class implements ReadableSwarmAuditSink
    {
        public function emit(string $category, array $payload): void {}

        public function forRun(string $runId): iterable
        {
            throw new \RuntimeException('downstream sink unavailable');
        }
    };
    app()->instance(SwarmAuditSink::class, $sink);

    $exit = Artisan::call('swarm:trace', ['run_id' => $runId, '--json' => true]);

    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['sources']['sink']['record_count'])->toBe(0);
    expect(implode("\n", $payload['notes']))->toContain('downstream sink unavailable');
});

test('sink throwing a TypeError surfaces in notes AND is logged via Log::error', function (): void {
    Log::spy();

    $runId = 'r-sink-programmer-error';
    seedTraceHistoryRow($runId);

    $sink = new class implements ReadableSwarmAuditSink
    {
        public function emit(string $category, array $payload): void {}

        public function forRun(string $runId): iterable
        {
            throw new TypeError('sink implementation bug: wrong return type');
        }
    };
    app()->instance(SwarmAuditSink::class, $sink);

    $exit = Artisan::call('swarm:trace', ['run_id' => $runId, '--json' => true]);

    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    // The trace continues with degraded sink data...
    expect($payload['sources']['sink']['record_count'])->toBe(0);
    expect(implode("\n", $payload['notes']))->toContain('wrong return type');

    // ...AND the programmer error is logged loudly so it can't hide behind
    // a benign-looking "degraded" note.
    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) {
            return str_contains($message, 'ReadableSwarmAuditSink::forRun() threw a programmer error')
                && ($context['error_class'] ?? '') === 'TypeError';
        })
        ->once();
});
