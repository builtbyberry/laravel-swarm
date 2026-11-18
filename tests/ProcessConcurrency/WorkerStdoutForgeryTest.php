<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\WorkerStdoutWritingAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\WorkerStdoutWritingParallelSwarm;

pest()->group('process-concurrency');

/*
 * Why Laravel 13.40 is the floor (CVE-2026-128083). Before it, the process
 * concurrency driver read a worker's whole standard output as its result, so
 * anything an agent or tool wrote there could break the run or stand in for
 * the result. The lowest-dependency CI lane runs this at exactly 13.40.0.
 */
test('what a parallel worker writes to standard output cannot break or replace its result', function (string $mode): void {
    $marker = sys_get_temp_dir().'/swarm-worker-stdout-'.getmypid().'-'.bin2hex(random_bytes(4));

    try {
        $response = WorkerStdoutWritingParallelSwarm::make()->run($mode.':'.$marker);

        $writers = array_map(
            static fn (string $path): int => (int) substr(strrchr($path, '.'), 1),
            glob($marker.'.*') ?: [],
        );

        // Both branches wrote from their own worker process, not from this one.
        expect($writers)->toHaveCount(2)
            ->and($writers)->not->toContain(getmypid())
            ->and(array_map(static fn ($step): string => $step->output, $response->steps))
            ->toBe(array_fill(0, 2, 'serialization-boundary:'.$mode.':'.$marker.':runtime-only'))
            ->and($response->output)->not->toContain(WorkerStdoutWritingAgent::FORGED_OUTPUT);
    } finally {
        array_map('unlink', glob($marker.'.*') ?: []);
    }
})->with(['stray-byte', 'forged-envelope']);
