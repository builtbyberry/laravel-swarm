<?php

declare(strict_types=1);

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;

pest()->group('process-concurrency', 'skip-locked-real-db');

/** @param array<string, mixed> $value */
function nativeApprovalCanonicalize(array $value): array
{
    ksort($value, SORT_STRING);

    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = nativeApprovalCanonicalize($item);
        }
    }

    return $value;
}

function nativeApprovalDecisionDigest(Decisions $decisions, string $pendingDigest): string
{
    $normalized = [];

    foreach ($decisions->all() as $id => $decision) {
        $normalized[$id] = [
            'action' => $decision->action,
            'arguments' => $decision->arguments === null ? null : nativeApprovalCanonicalize($decision->arguments),
            'result' => $decision->result,
        ];
    }

    ksort($normalized, SORT_STRING);

    return hash('sha256', json_encode([
        'pending_digest' => $pendingDigest,
        'decisions' => $normalized,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/** @param array<string, array<string, mixed>> $pendingSet */
function nativeApprovalPendingDigest(array $pendingSet): string
{
    return hash('sha256', json_encode(
        nativeApprovalCanonicalize($pendingSet),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ));
}

/**
 * @param  array<string, Decision|bool>  $decisionMap
 */
function nativeApprovalDecisionRaceWorker(
    string $key,
    int $revision,
    int $fence,
    array $decisionMap,
    string $role = 'ordinary',
): Closure {
    $decisions = Decisions::from($decisionMap);
    $normalizedDecisions = [];

    foreach ($decisions->all() as $id => $decision) {
        $normalizedDecisions[$id] = [
            'action' => $decision->action,
            'arguments' => $decision->arguments === null ? null : nativeApprovalCanonicalize($decision->arguments),
            'result' => $decision->result,
        ];
    }

    ksort($normalizedDecisions, SORT_STRING);
    $decisionIds = array_keys($normalizedDecisions);

    return static function () use ($key, $revision, $fence, $normalizedDecisions, $decisionIds, $role): string {
        $signal = static function (string $name): void {
            $default = DB::getDefaultConnection();
            config()->set('database.connections.native_approval_signal', config("database.connections.{$default}"));
            DB::purge('native_approval_signal');
            DB::connection('native_approval_signal')->table('native_approval_race_signals')->insert([
                'name' => $name,
                'created_at' => now('UTC'),
            ]);
            DB::disconnect('native_approval_signal');
        };
        $await = static function (string $name): void {
            $default = DB::getDefaultConnection();
            config()->set('database.connections.native_approval_signal_wait', config("database.connections.{$default}"));
            DB::purge('native_approval_signal_wait');
            $deadline = microtime(true) + 10;

            while (microtime(true) < $deadline) {
                if (DB::connection('native_approval_signal_wait')->table('native_approval_race_signals')->where('name', $name)->exists()) {
                    DB::disconnect('native_approval_signal_wait');

                    return;
                }

                usleep(10_000);
            }

            DB::disconnect('native_approval_signal_wait');

            throw new RuntimeException("Race signal [{$name}] was not observed.");
        };

        if ($role === 'contender') {
            $await('holder-locked');
            $signal('contender-ready');
        }

        return DB::transaction(function () use ($key, $revision, $fence, $normalizedDecisions, $decisionIds, $role, $signal, $await): string {
            $wait = DB::table('native_approval_race_waits')
                ->where('tenant_id', 'tenant-a')
                ->where('run_id', 'race-run')
                ->lockForUpdate()
                ->sole();

            if ($role === 'holder') {
                $signal('holder-locked');
                $await('contender-ready');
                usleep(250_000);
            }

            $canonicalize = static function (array $value) use (&$canonicalize): array {
                ksort($value, SORT_STRING);

                foreach ($value as $itemKey => $item) {
                    if (is_array($item)) {
                        $value[$itemKey] = $canonicalize($item);
                    }
                }

                return $value;
            };
            $pendingSet = $canonicalize(json_decode($wait->pending_set, true, flags: JSON_THROW_ON_ERROR));
            $pendingDigest = hash('sha256', json_encode(
                $pendingSet,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            $pendingIds = array_keys($pendingSet);

            if ($wait->state !== 'pending'
                || (int) $wait->revision !== $revision
                || (int) $wait->fence !== $fence
                || $wait->pending_digest !== $pendingDigest) {
                return 'stale';
            }
            if ($decisionIds !== $pendingIds) {
                return 'invalid_set';
            }

            $digest = hash('sha256', json_encode([
                'pending_digest' => $pendingDigest,
                'decisions' => $normalizedDecisions,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if ($wait->decision_digest !== null) {
                if ($role === 'contender') {
                    $signal('contender-acquired');
                }

                return hash_equals($wait->decision_digest, $digest) ? 'duplicate' : 'conflict';
            }

            DB::table('native_approval_race_intents')->insert([
                'idempotency_key' => $key,
                'tenant_id' => 'tenant-a',
                'run_id' => 'race-run',
                'revision' => $revision,
                'fence' => $fence,
                'pending_digest' => $pendingDigest,
                'decision_digest' => $digest,
            ]);
            DB::table('native_approval_race_waits')
                ->where('tenant_id', 'tenant-a')
                ->where('run_id', 'race-run')
                ->update(['decision_digest' => $digest]);

            if ($role === 'holder') {
                $signal('holder-releasing');
            }

            return 'accepted';
        });
    };
}

function nativeApprovalResetRace(): void
{
    DB::table('native_approval_race_intents')->delete();
    DB::table('native_approval_race_signals')->delete();
    DB::table('native_approval_race_waits')->update([
        'state' => 'pending',
        'decision_digest' => null,
    ]);
}

test('native approval decision ingress serializes canonical duplicates conflicts and stale fences', function (): void {
    if (in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true)) {
        $this->markTestSkipped('Native approval decision races require MySQL or PostgreSQL row locks.');
    }

    Schema::dropIfExists('native_approval_race_signals');
    Schema::dropIfExists('native_approval_race_intents');
    Schema::dropIfExists('native_approval_race_waits');
    Schema::create('native_approval_race_waits', function (Blueprint $table): void {
        $table->string('tenant_id');
        $table->string('run_id');
        $table->unsignedInteger('revision');
        $table->unsignedInteger('fence');
        $table->string('state');
        $table->text('pending_set');
        $table->string('pending_digest');
        $table->string('decision_digest')->nullable();
        $table->primary(['tenant_id', 'run_id']);
    });
    Schema::create('native_approval_race_intents', function (Blueprint $table): void {
        $table->string('idempotency_key')->primary();
        $table->string('tenant_id');
        $table->string('run_id');
        $table->unsignedInteger('revision');
        $table->unsignedInteger('fence');
        $table->string('pending_digest');
        $table->string('decision_digest');
        $table->unique(['tenant_id', 'run_id', 'revision']);
    });
    Schema::create('native_approval_race_signals', function (Blueprint $table): void {
        $table->increments('sequence');
        $table->string('name')->unique();
        $table->timestamp('created_at');
    });

    $firstPendingOrder = [
        'approval-a' => ['arguments' => ['value' => 'a']],
        'approval-b' => ['arguments' => ['value' => 'b']],
    ];
    $oppositePendingOrder = [
        'approval-b' => ['arguments' => ['value' => 'b']],
        'approval-a' => ['arguments' => ['value' => 'a']],
    ];
    $pendingDigest = nativeApprovalPendingDigest($firstPendingOrder);
    expect(nativeApprovalPendingDigest($oppositePendingOrder))->toBe($pendingDigest);
    DB::table('native_approval_race_waits')->insert([
        'tenant_id' => 'tenant-a',
        'run_id' => 'race-run',
        'revision' => 3,
        'fence' => 9,
        'state' => 'pending',
        'pending_set' => json_encode($oppositePendingOrder, JSON_THROW_ON_ERROR),
        'pending_digest' => $pendingDigest,
    ]);

    $firstOrder = [
        'approval-a' => Decision::approve(),
        'approval-b' => Decision::edit(['z' => 2, 'a' => 1]),
    ];
    $oppositeOrder = [
        'approval-b' => Decision::edit(['a' => 1, 'z' => 2]),
        'approval-a' => Decision::approve(),
    ];
    $concurrency = app(ConcurrencyManager::class)->driver('process');
    expect(nativeApprovalDecisionRaceWorker('partial', 3, 9, [
        'approval-a' => Decision::approve(),
    ])())->toBe('invalid_set')
        ->and(nativeApprovalDecisionRaceWorker('extra', 3, 9, [
            ...$firstOrder,
            'approval-c' => Decision::approve(),
        ])())->toBe('invalid_set')
        ->and(DB::table('native_approval_race_intents')->count())->toBe(0);

    $same = $concurrency->run([
        nativeApprovalDecisionRaceWorker('same-a', 3, 9, $firstOrder, 'holder'),
        nativeApprovalDecisionRaceWorker('same-b', 3, 9, $oppositeOrder, 'contender'),
    ]);
    sort($same);
    $signals = DB::table('native_approval_race_signals')->orderBy('sequence')->pluck('name')->all();
    $accepted = DB::table('native_approval_race_intents')->sole();
    $expectedDigest = nativeApprovalDecisionDigest(Decisions::from($firstOrder), $pendingDigest);
    expect($same)->toBe(['accepted', 'duplicate'])
        ->and($signals)->toBe(['holder-locked', 'contender-ready', 'holder-releasing', 'contender-acquired'])
        ->and((int) $accepted->revision)->toBe(3)
        ->and((int) $accepted->fence)->toBe(9)
        ->and($accepted->pending_digest)->toBe($pendingDigest)
        ->and($accepted->decision_digest)->toBe($expectedDigest)
        ->and(DB::table('native_approval_race_intents')->count())->toBe(1);

    nativeApprovalResetRace();
    $conflicting = $concurrency->run([
        nativeApprovalDecisionRaceWorker('conflict-a', 3, 9, $firstOrder, 'holder'),
        nativeApprovalDecisionRaceWorker('conflict-b', 3, 9, [
            'approval-a' => Decision::reject('no'),
            'approval-b' => Decision::edit(['a' => 1, 'z' => 2]),
        ], 'contender'),
    ]);
    sort($conflicting);
    expect($conflicting)->toBe(['accepted', 'conflict'])
        ->and(DB::table('native_approval_race_intents')->count())->toBe(1)
        ->and(nativeApprovalDecisionRaceWorker('stale-revision', 2, 9, $firstOrder)())->toBe('stale')
        ->and(nativeApprovalDecisionRaceWorker('stale-fence', 3, 8, $firstOrder)())->toBe('stale');

    nativeApprovalResetRace();
    DB::table('native_approval_race_waits')->update(['state' => 'cancelled']);
    expect(nativeApprovalDecisionRaceWorker('cancelled', 3, 9, $firstOrder)())->toBe('stale');
});
