<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing\Memory;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use Illuminate\Testing\Assert as PHPUnit;

/**
 * Test double for {@see MemoryCapturePolicy} that records every decision call.
 *
 * Wraps an optional delegate policy and forwards each write decision to it,
 * recording the scope, key, context, actor, and resulting decision so tests can
 * assert what the {@see RedactingMemoryStore}
 * decorator saw during a run. When no delegate is supplied, every write returns
 * {@see CaptureDecision::Full} so the recorder is usable as a stand-alone
 * record-everything policy.
 *
 * SwarmFake installs this via {@see SwarmFake::interceptMemoryCapturePolicy()},
 * which swaps the container binding and flushes the {@see MemoryStore}
 * singleton so the decorator re-resolves with the recorder on the next write.
 */
class RecordingMemoryCapturePolicy implements MemoryCapturePolicy
{
    /**
     * @var array<int, array{scope: MemoryScope, key: string, context: ?RunContext, actor: ?Actor, decision: CaptureDecision}>
     */
    protected array $records = [];

    public function __construct(
        protected ?MemoryCapturePolicy $delegate = null,
    ) {}

    public function memory(
        MemoryScope $scope,
        string $key,
        ?RunContext $context = null,
        ?Actor $actor = null,
    ): CaptureDecision {
        $decision = $this->delegate?->memory($scope, $key, $context, $actor) ?? CaptureDecision::Full;

        $this->records[] = [
            'scope' => $scope,
            'key' => $key,
            'context' => $context,
            'actor' => $actor,
            'decision' => $decision,
        ];

        return $decision;
    }

    /**
     * Every recorded decision in the order it was emitted.
     *
     * @return array<int, array{scope: MemoryScope, key: string, context: ?RunContext, actor: ?Actor, decision: CaptureDecision}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Records for a single memory key.
     *
     * @return array<int, array{scope: MemoryScope, key: string, context: ?RunContext, actor: ?Actor, decision: CaptureDecision}>
     */
    public function recordsFor(string $key): array
    {
        return array_values(array_filter(
            $this->records,
            fn (array $record): bool => $record['key'] === $key,
        ));
    }

    /**
     * Assert the policy was consulted for the given key at least once.
     *
     * Pass a callable matcher to inspect the recorded entry (scope, key,
     * context, actor, decision). Without a matcher the assertion only verifies
     * the key was seen.
     */
    public function assertConsulted(string $key, ?callable $matcher = null): void
    {
        $records = $this->recordsFor($key);

        if ($matcher === null) {
            PHPUnit::assertNotEmpty(
                $records,
                "MemoryCapturePolicy was not consulted for key [{$key}].",
            );

            return;
        }

        PHPUnit::assertTrue(
            collect($records)->contains(fn (array $record): bool => (bool) $matcher($record)),
            "MemoryCapturePolicy was not consulted for key [{$key}] with a record matching the expected matcher.",
        );
    }

    /**
     * Assert the policy returned the given decision for the given key at least
     * once.
     */
    public function assertDecision(string $key, CaptureDecision $decision): void
    {
        PHPUnit::assertTrue(
            collect($this->recordsFor($key))->contains(fn (array $record): bool => $record['decision'] === $decision),
            "MemoryCapturePolicy did not return [{$decision->name}] for key [{$key}].",
        );
    }

    /**
     * Assert the policy was never consulted (optionally for one key only).
     */
    public function assertNeverConsulted(?string $key = null): void
    {
        if ($key === null) {
            PHPUnit::assertEmpty(
                $this->records,
                'MemoryCapturePolicy was consulted unexpectedly.',
            );

            return;
        }

        PHPUnit::assertEmpty(
            $this->recordsFor($key),
            "MemoryCapturePolicy was consulted for key [{$key}] unexpectedly.",
        );
    }
}
