<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\HaltsSwarmExecution;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Exceptions\AuditSinkHaltedException;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;

class HaltingSink implements SwarmAuditSink
{
    public function emit(string $category, array $payload): void
    {
        throw new RuntimeException("intentional sink failure for category {$category}");
    }
}

beforeEach(function (): void {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    app()->instance(SwarmAuditSink::class, new HaltingSink);
    config(['swarm.audit.failure_policy' => 'halt']);
});

test('halt policy surfaces AuditSinkHaltedException from a sync run', function (): void {
    try {
        FakeSequentialSwarm::make()->run('task');
        $this->fail('Expected AuditSinkHaltedException.');
    } catch (AuditSinkHaltedException $halt) {
        expect($halt)->toBeInstanceOf(HaltsSwarmExecution::class);
        expect($halt->category)->toBe('run.started');
        expect($halt->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});

test('run.failed audit emit is skipped when the cause already halted', function (): void {
    // Halting sink with a per-call counter to verify we did not double-emit
    $countingSink = new class extends HaltingSink
    {
        public array $categoriesAttempted = [];

        public function emit(string $category, array $payload): void
        {
            $this->categoriesAttempted[] = $category;
            parent::emit($category, $payload);
        }
    };
    app()->instance(SwarmAuditSink::class, $countingSink);

    try {
        FakeSequentialSwarm::make()->run('task');
    } catch (AuditSinkHaltedException) {
        // expected
    }

    // Only the originating category (run.started) was attempted —
    // run.failed was suppressed because the failure already halted.
    expect($countingSink->categoriesAttempted)->toBe(['run.started']);
});
