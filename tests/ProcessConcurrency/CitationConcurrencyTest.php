<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationParallelPlanSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationParallelSwarm;

pest()->group('process-concurrency');

it('preserves source ownership and ranges across actual process serialization', function (string $path) {
    $swarm = $path === 'parallel' ? new CitationParallelSwarm : new CitationParallelPlanSwarm;
    $response = $path === 'stream' ? $swarm->stream('task') : $swarm->prompt('task');
    if ($path === 'stream') {
        iterator_to_array($response);
        $response = $response->streamedResponse;
    }
    expect($response->steps)->toHaveCount(2)
        ->and($response->steps[0]->citations[0]->url)->toBe('https://secret.example/CitingAgent/1')
        ->and($response->steps[1]->citations[0]->url)->toBe('https://secret.example/OtherCitingAgent/1')
        ->and($response->steps[1]->citations[0]->stepIndex)->toBe(1)
        ->and($response->steps[1]->citations[0]->startIndex)->toBe(1)
        ->and($response->citations)->toHaveCount($path === 'parallel' ? 2 : 1)
        ->and($response->citations[0]->url)->toBe('https://secret.example/CitingAgent/1');
})->with(['parallel', 'hierarchy', 'stream']);

it('uses the parent citation bound inside each process worker', function (string $path) {
    config()->set('swarm.citations.max_count', 0);
    $swarm = $path === 'parallel' ? new CitationParallelSwarm : new CitationParallelPlanSwarm;
    $response = $path === 'stream' ? $swarm->stream('task') : $swarm->prompt('task');
    if ($path === 'stream') {
        iterator_to_array($response);
        $response = $response->streamedResponse;
    }
    expect($response->steps[0]->citationEvidence->status)->toBe('partial')
        ->and($response->steps[0]->citations)->toBe([])
        ->and($response->steps[1]->citationEvidence->reasons)->toBe(['limit'])
        ->and($response->citations)->toBe([]);
})->with(['parallel', 'hierarchy', 'stream']);
