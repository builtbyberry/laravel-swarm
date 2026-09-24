<?php

declare(strict_types=1);

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\Decisions;

pest()->group('process-concurrency', 'skip-locked-real-db');

function nativeApprovalDecisionRaceWorker(string $key, int $revision, int $fence, bool $approved): Closure
{
    return static function () use ($key, $revision, $fence, $approved): string {
        $decisions = Decisions::from(['approval-call' => $approved]);
        $digest = hash('sha256', serialize($decisions->all()));

        return DB::transaction(function () use ($key, $revision, $fence, $digest): string {
            $wait = DB::table('native_approval_race_waits')->where('run_id', 'race-run')->lockForUpdate()->sole();
            if ((int) $wait->revision !== $revision || (int) $wait->fence !== $fence) {
                return 'stale';
            }
            if ($wait->decision_digest !== null) {
                return hash_equals($wait->decision_digest, $digest) ? 'duplicate' : 'conflict';
            }

            DB::table('native_approval_race_intents')->insert([
                'idempotency_key' => $key,
                'run_id' => 'race-run',
                'revision' => $revision,
                'decision_digest' => $digest,
            ]);
            DB::table('native_approval_race_waits')->where('run_id', 'race-run')->update(['decision_digest' => $digest]);

            return 'accepted';
        });
    };
}

test('native approval decision ingress serializes duplicates conflicts and stale fences', function (): void {
    if (in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true)) {
        $this->markTestSkipped('Native approval decision races require MySQL or PostgreSQL row locks.');
    }

    Schema::dropIfExists('native_approval_race_intents');
    Schema::dropIfExists('native_approval_race_waits');
    Schema::create('native_approval_race_waits', function (Blueprint $table): void {
        $table->string('run_id')->primary();
        $table->unsignedInteger('revision');
        $table->unsignedInteger('fence');
        $table->string('decision_digest')->nullable();
    });
    Schema::create('native_approval_race_intents', function (Blueprint $table): void {
        $table->string('idempotency_key')->primary();
        $table->string('run_id');
        $table->unsignedInteger('revision');
        $table->string('decision_digest');
        $table->unique(['run_id', 'revision']);
    });
    DB::table('native_approval_race_waits')->insert(['run_id' => 'race-run', 'revision' => 3, 'fence' => 9]);

    $concurrency = app(ConcurrencyManager::class)->driver('process');
    $same = $concurrency->run([
        nativeApprovalDecisionRaceWorker('same-a', 3, 9, true),
        nativeApprovalDecisionRaceWorker('same-b', 3, 9, true),
    ]);
    sort($same);
    expect($same)->toBe(['accepted', 'duplicate'])
        ->and(DB::table('native_approval_race_intents')->count())->toBe(1);

    DB::table('native_approval_race_intents')->delete();
    DB::table('native_approval_race_waits')->update(['decision_digest' => null]);
    $conflicting = $concurrency->run([
        nativeApprovalDecisionRaceWorker('conflict-a', 3, 9, true),
        nativeApprovalDecisionRaceWorker('conflict-b', 3, 9, false),
    ]);
    sort($conflicting);
    expect($conflicting)->toBe(['accepted', 'conflict'])
        ->and(DB::table('native_approval_race_intents')->count())->toBe(1)
        ->and(nativeApprovalDecisionRaceWorker('stale-revision', 2, 9, true)())->toBe('stale')
        ->and(nativeApprovalDecisionRaceWorker('stale-fence', 3, 8, true)())->toBe('stale');
});
