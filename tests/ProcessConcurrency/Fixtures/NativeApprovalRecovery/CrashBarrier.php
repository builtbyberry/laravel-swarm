<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\NativeApprovalRecovery;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CrashBarrier
{
    public static function matches(string $boundary): bool
    {
        return getenv('SWARM_NATIVE_APPROVAL_BOUNDARY') === $boundary
            && config('tests.native_approval_recovering') !== true;
    }

    public static function trip(string $boundary): never
    {
        DB::table('native_approval_proof_barriers')->insertOrIgnore([
            'name' => $boundary,
            'created_at' => now('UTC'),
        ]);

        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            DB::connection()->disconnect();
            DB::connection()->reconnect();

            if (DB::table('native_approval_proof_releases')->where('name', $boundary)->exists()) {
                if (! function_exists('posix_kill')) {
                    throw new RuntimeException('The native approval crash proof requires posix_kill.');
                }

                posix_kill(getmypid(), SIGKILL);

                throw new RuntimeException('SIGKILL did not terminate the crash-proof worker.');
            }

            usleep(10_000);
        }

        throw new RuntimeException("Parent did not release crash barrier [{$boundary}].");
    }
}
