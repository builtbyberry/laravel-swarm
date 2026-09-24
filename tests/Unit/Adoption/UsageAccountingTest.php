<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Concerns\MergesAgentUsage;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchCoordinator;
use Laravel\Ai\Responses\Data\TextUsage;

function usageAccountingFold(): object
{
    return new class
    {
        use MergesAgentUsage;

        public function report(array $total, array $report): array
        {
            return $this->mergeUsageReport($total, $report);
        }

        public function combine(array $left, array $right): array
        {
            return $this->mergeUsage($left, $right);
        }
    };
}

function unavailableUsageAccounting(): array
{
    return array_fill_keys(['input_tokens', 'output_tokens', 'prompt_tokens', 'completion_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'], null);
}

it('distinguishes no contributors from a real empty or unclassifiable usage report', function () {
    $fold = usageAccountingFold();
    $known = ['input_tokens' => 8, 'output_tokens' => 3, 'cache_read_input_tokens' => 2, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 1];
    expect($fold->combine([], []))->toBe([])
        ->and($fold->combine($known, []))->toBe($known)
        ->and($fold->combine([], $known))->toBe($known)
        ->and($fold->report([], []))->toBe(unavailableUsageAccounting())
        ->and($fold->report($known, []))->toBe(unavailableUsageAccounting())
        ->and($fold->report([], ['total_tokens' => 11]))->toBe(unavailableUsageAccounting())
        ->and($fold->report($fold->report([], []), $known))->toBe(unavailableUsageAccounting());
});

it('sums compatible inclusive counters without adding optional subsets', function (string $input, string $output) {
    $fold = usageAccountingFold();
    $first = [$input => 10, $output => 4, 'cache_read_input_tokens' => 6, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 2];
    $second = [$input => 20, $output => 8, 'cache_read_input_tokens' => 5, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 3];
    expect($fold->report($fold->report([], $first), $second))->toBe([
        $input => 30, $output => 12, 'cache_read_input_tokens' => 11, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 5,
    ])->and($first[$input])->toBe(10);
})->with([['input_tokens', 'output_tokens'], ['prompt_tokens', 'completion_tokens']]);

it('preserves unknown categories independently and never coerces malformed counts', function (mixed $value) {
    $fold = usageAccountingFold();
    $known = ['input_tokens' => 10, 'output_tokens' => 3, 'cache_read_input_tokens' => 4, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 1];
    $bad = ['input_tokens' => $value, 'output_tokens' => 0, 'cache_read_input_tokens' => $value, 'reasoning_tokens' => 0];
    expect($fold->report($fold->report([], $known), $bad))->toBe([
        'input_tokens' => null, 'output_tokens' => 3, 'cache_read_input_tokens' => null, 'cache_write_input_tokens' => null, 'reasoning_tokens' => 1,
    ]);
})->with([[null], ['2'], [true], [false], [-1], [1.5], [[]], [new stdClass]]);

it('does not relabel or combine the shared subsets of legacy and native reports', function () {
    $fold = usageAccountingFold();
    $legacy = ['prompt_tokens' => 10, 'completion_tokens' => 4, 'cache_read_input_tokens' => 6];
    $native = ['input_tokens' => 10, 'output_tokens' => 4, 'cache_read_input_tokens' => 6];
    expect($fold->report($fold->report([], $legacy), $native))->toBe(unavailableUsageAccounting())
        ->and($fold->report($fold->report([], $native), $legacy))->toBe(unavailableUsageAccounting())
        ->and($fold->report([], $native + $legacy))->toBe(unavailableUsageAccounting())
        ->and($legacy)->toBe(['prompt_tokens' => 10, 'completion_tokens' => 4, 'cache_read_input_tokens' => 6]);
});

it('keeps normalized combination associative and permutation invariant after reload', function () {
    $fold = usageAccountingFold();
    $durable = app(DurableBranchCoordinator::class);
    $reports = [
        ['input_tokens' => 7, 'output_tokens' => 2, 'cache_read_input_tokens' => 3, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 1],
        ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 0],
        ['input_tokens' => null, 'output_tokens' => 3],
        ['prompt_tokens' => 4, 'completion_tokens' => 5],
        [],
        unavailableUsageAccounting(),
    ];
    foreach ($reports as $a) {
        foreach ($reports as $b) {
            foreach ($reports as $c) {
                $normalized = array_map(fn (array $r): array => json_decode(json_encode($fold->report([], $r), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR), [$a, $b, $c]);
                [$x, $y, $z] = $normalized;
                $expected = $fold->combine($fold->combine($x, $y), $z);
                foreach ([[$a, $b, $c], [$a, $c, $b], [$b, $a, $c], [$b, $c, $a], [$c, $a, $b], [$c, $b, $a]] as $ordered) {
                    expect($durable->mergeBranchUsage(array_map(fn (array $report): array => ['usage' => $report], $ordered)))->toBe($expected);
                }
                foreach ([[$x, $y, $z], [$x, $z, $y], [$y, $x, $z], [$y, $z, $x], [$z, $x, $y], [$z, $y, $x]] as [$l, $m, $r]) {
                    expect($fold->combine($fold->combine($l, $m), $r))->toBe($expected)
                        ->and($fold->combine($l, $fold->combine($m, $r)))->toBe($expected);
                }
            }
        }
    }
});

it('uses the same conservative fold for durable branch reports', function () {
    $coordinator = app(DurableBranchCoordinator::class);
    $known = ['input_tokens' => 12, 'output_tokens' => 5, 'cache_read_input_tokens' => 3];
    $unknownSubset = ['input_tokens' => 2, 'output_tokens' => 1, 'cache_read_input_tokens' => null];
    expect($coordinator->mergeBranchUsage([]))->toBe([])
        ->and($coordinator->mergeBranchUsage([['usage' => $known], ['usage' => $unknownSubset]]))->toBe([
            'input_tokens' => 14, 'output_tokens' => 6, 'cache_read_input_tokens' => null, 'cache_write_input_tokens' => null, 'reasoning_tokens' => null,
        ])
        ->and($coordinator->mergeBranchUsage([['usage' => $known], ['usage' => []]]))->toBe(unavailableUsageAccounting())
        ->and($coordinator->mergeBranchUsage([['usage' => $known], []]))->toBe(unavailableUsageAccounting())
        ->and($coordinator->mergeBranchUsage([['usage' => ['input_tokens' => null, 'output_tokens' => null]]]))->toBe(array_fill_keys(['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'], null));
});

it('preserves a native invocation subtotal without claiming generation completeness', function () {
    $first = new TextUsage(10, 4, cacheReadInputTokens: 3);
    $second = new TextUsage(20, 5, cacheReadInputTokens: null);
    $nativeReport = $first->add($second)->toArray();
    $fold = usageAccountingFold();
    expect($fold->report([], $nativeReport)['cache_read_input_tokens'])->toBe(3)
        ->and($fold->report($fold->report([], $first->toArray()), $second->toArray())['cache_read_input_tokens'])->toBeNull();
});
