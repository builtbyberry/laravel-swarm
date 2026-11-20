<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableNodeStreamRecorder;
use Symfony\Component\Finder\Finder;

/*
 * A batching DurableNodeStreamSink holds deltas until it is flushed, and only its
 * caller knows when the attempt's step has succeeded. This guard fails when a
 * source file obtains a sink (sinkFor() or HierarchicalRunner::durableNodeSink())
 * without a matching ->flush(), so a new durable-streaming path cannot silently
 * drop a successful node's trailing deltas.
 */
test('every source file that obtains a durable node stream sink also flushes it', function () {
    $checked = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 3).'/src')->name('*.php') as $file) {
        $source = $file->getContents();
        // A method that returns the sink (durableNodeSink() itself) hands the
        // flush to its callers, which are counted where they call it.
        $obtained = preg_match_all('/(?<!return )\$this->(?:nodeStream->sinkFor|durableNodeSink)\(/', $source);

        if ($obtained === 0) {
            continue;
        }

        $checked[$file->getRelativePathname()] = [
            'obtained' => $obtained,
            'flushed' => preg_match_all('/\$sink\??->flush\(\)/', $source),
        ];
    }

    // The known call sites, so a rename that hides them from this scan fails too.
    expect(array_keys($checked))->toEqualCanonicalizing([
        'Runners/Durable/DurableSequentialStepAdvancer.php',
        'Runners/Durable/DurableBranchAdvancer.php',
        'Runners/HierarchicalRunner.php',
    ]);

    foreach ($checked as $path => $counts) {
        expect($counts['flushed'])->toBeGreaterThanOrEqual($counts['obtained'], "{$path} obtains a sink without flushing it");
    }
})->covers(DurableNodeStreamRecorder::class);
