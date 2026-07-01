<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\StructuredOutputStreamingException;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\StructuredOutputWorker;

test('guard throws for a structured-output agent, naming the node, agent, and remedy', function (): void {
    try {
        StructuredOutputStreamingException::guard(new StructuredOutputWorker, 'step:2');
        $this->fail('Expected StructuredOutputStreamingException.');
    } catch (StructuredOutputStreamingException $exception) {
        expect($exception->getMessage())
            ->toContain('step:2')
            ->toContain(StructuredOutputWorker::class)
            ->toContain('cannot be streamed')
            ->toContain('Remove HasStructuredOutput');
    }
});

test('guard is a no-op for a non-structured agent', function (): void {
    StructuredOutputStreamingException::guard(new FakeResearcher, 'step:0');

    expect(true)->toBeTrue();
});

test('forAgent without a node label names the agent, not a node', function (): void {
    $exception = StructuredOutputStreamingException::forAgent('Acme\\Widget');

    expect($exception->getMessage())
        ->toContain('Agent [Acme\\Widget]')
        ->not->toContain('Node [');
});
