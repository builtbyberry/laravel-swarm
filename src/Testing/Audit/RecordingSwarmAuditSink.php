<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use Illuminate\Testing\Assert as PHPUnit;

/**
 * Test double for {@see SwarmAuditSink} that records every emitted evidence
 * payload and exposes assertions over the audit trail a run produced.
 *
 * Install it with {@see \BuiltByBerry\LaravelSwarm\Testing\SwarmFake::interceptSwarmAuditSink()},
 * which swaps the container binding and flushes the dispatcher so the next
 * run records into this recorder:
 *
 *   $audit = SwarmFake::interceptSwarmAuditSink();
 *   MySwarm::make()->prompt($task);
 *   $audit->assertAuditChain(['run.started', 'step.started', 'step.completed', 'run.completed']);
 *   $audit->assertStepCount(1);
 *
 * An optional delegate is forwarded behind the recorder so a real sink still
 * receives every payload while the recorder observes it.
 */
class RecordingSwarmAuditSink implements SwarmAuditSink
{
    /**
     * Every emitted payload in the order it was received. The dispatcher
     * enriches each payload with its `category`, so the category is readable
     * off the record itself.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $records = [];

    public function __construct(
        protected ?SwarmAuditSink $delegate = null,
    ) {}

    public function emit(string $category, array $payload): void
    {
        $this->records[] = $payload;

        $this->delegate?->emit($category, $payload);
    }

    /**
     * Every recorded payload, in emission order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * The emitted categories, in order (with duplicates preserved).
     *
     * @return array<int, string>
     */
    public function categories(): array
    {
        return array_map(
            fn (array $record): string => (string) ($record['category'] ?? ''),
            $this->records,
        );
    }

    /**
     * Records emitted under a single category, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recordsFor(string $category): array
    {
        return array_values(array_filter(
            $this->records,
            fn (array $record): bool => ($record['category'] ?? null) === $category,
        ));
    }

    public function hasCategory(string $category): bool
    {
        return $this->recordsFor($category) !== [];
    }

    /**
     * Forget everything recorded so far.
     */
    public function reset(): void
    {
        $this->records = [];
    }

    /**
     * Assert audit evidence was emitted for the given category at least once.
     *
     * Pass a callable matcher to inspect the payload; without one the
     * assertion only verifies the category appeared.
     */
    public function assertEmittedAudit(string $category, ?callable $matcher = null): void
    {
        $records = $this->recordsFor($category);

        if ($matcher === null) {
            PHPUnit::assertNotEmpty(
                $records,
                "No audit evidence was emitted for category [{$category}]. Emitted: [".implode(', ', $this->categories()).'].',
            );

            return;
        }

        PHPUnit::assertTrue(
            collect($records)->contains(fn (array $record): bool => (bool) $matcher($record)),
            "No audit evidence for category [{$category}] matched the expected matcher.",
        );
    }

    /**
     * Assert no audit evidence was emitted for the given category.
     */
    public function assertNotEmittedAudit(string $category): void
    {
        PHPUnit::assertEmpty(
            $this->recordsFor($category),
            "Unexpected audit evidence was emitted for category [{$category}].",
        );
    }

    /**
     * Assert the given categories appear, in order, within the emitted trail.
     *
     * The chain is matched as an ordered subsequence — additional evidence
     * between the expected categories is allowed — so a caller can assert the
     * backbone (`run.started → … → run.completed`) without enumerating every
     * step event.
     *
     * @param  array<int, string>  $categories
     */
    public function assertAuditChain(array $categories): void
    {
        $emitted = $this->categories();
        $cursor = 0;

        foreach ($emitted as $category) {
            if ($cursor < count($categories) && $category === $categories[$cursor]) {
                $cursor++;
            }
        }

        PHPUnit::assertSame(
            count($categories),
            $cursor,
            'The expected audit chain ['.implode(' → ', $categories).'] did not appear in order. Emitted: ['.implode(' → ', $emitted).'].',
        );
    }

    /**
     * Assert the run recorded exactly the given number of agent steps
     * (counted by `step.started` evidence).
     */
    public function assertStepCount(int $expected): void
    {
        $actual = count($this->recordsFor('step.started'));

        PHPUnit::assertSame(
            $expected,
            $actual,
            "Expected [{$expected}] step(s) in the audit trail, but recorded [{$actual}].",
        );
    }
}
