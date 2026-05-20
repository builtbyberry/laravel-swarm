<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing\Audit;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use Illuminate\Testing\Assert as PHPUnit;

/**
 * Test double for {@see CapturePolicy} that records every decision call.
 *
 * Wraps an optional delegate policy and forwards each category call to it,
 * recording the category name, context, actor, and resulting decision so
 * tests can assert what the audit dispatcher would have seen during a run.
 * When no delegate is supplied, every category returns
 * {@see CaptureDecision::Full} so the recorder is usable as a stand-alone
 * policy that records every dispatcher invocation without further setup.
 *
 * SwarmFake installs this via {@see SwarmFake::interceptCapturePolicy()},
 * which swaps the container binding only. SwarmFake itself never constructs
 * or invokes the audit dispatcher; recording happens when the real
 * dispatcher resolves the contract from the container during a non-faked
 * run.
 */
class RecordingCapturePolicy implements CapturePolicy
{
    /**
     * @var array<int, array{category: string, context: ?RunContext, actor: ?Actor, decision: CaptureDecision}>
     */
    protected array $records = [];

    public function __construct(
        protected ?CapturePolicy $delegate = null,
    ) {}

    public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->record('inputs', $context, $actor);
    }

    public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->record('outputs', $context, $actor);
    }

    public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->record('artifacts', $context, $actor);
    }

    public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->record('active_context', $context, $actor);
    }

    /**
     * Every recorded decision in the order it was emitted.
     *
     * @return array<int, array{category: string, context: ?RunContext, actor: ?Actor, decision: CaptureDecision}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Records for a single capture category.
     *
     * @return array<int, array{category: string, context: ?RunContext, actor: ?Actor, decision: CaptureDecision}>
     */
    public function recordsFor(string $category): array
    {
        return array_values(array_filter(
            $this->records,
            fn (array $record): bool => $record['category'] === $category,
        ));
    }

    /**
     * Assert the policy was invoked for the given category at least once.
     *
     * Pass a callable matcher to inspect the recorded entry (category,
     * context, actor, decision). Without a matcher the assertion only
     * verifies the category was touched.
     */
    public function assertCaptured(string $category, ?callable $matcher = null): void
    {
        $records = $this->recordsFor($category);

        if ($matcher === null) {
            PHPUnit::assertNotEmpty(
                $records,
                "CapturePolicy was not invoked for category [{$category}].",
            );

            return;
        }

        PHPUnit::assertTrue(
            collect($records)->contains(fn (array $record): bool => (bool) $matcher($record)),
            "CapturePolicy was not invoked for category [{$category}] with a record matching the expected matcher.",
        );
    }

    /**
     * Assert the policy returned the given decision for the given category
     * at least once.
     */
    public function assertCapturedDecision(string $category, CaptureDecision $decision): void
    {
        PHPUnit::assertTrue(
            collect($this->recordsFor($category))->contains(fn (array $record): bool => $record['decision'] === $decision),
            "CapturePolicy did not return [{$decision->name}] for category [{$category}].",
        );
    }

    /**
     * Assert at least one capture record across any category satisfies the
     * matcher. Useful for "any category saw this actor" style checks.
     */
    public function assertCapturedWith(callable $matcher): void
    {
        PHPUnit::assertTrue(
            collect($this->records)->contains(fn (array $record): bool => (bool) $matcher($record)),
            'CapturePolicy did not record an invocation matching the expected matcher.',
        );
    }

    /**
     * Assert the policy was never invoked at all.
     */
    public function assertNeverCaptured(?string $category = null): void
    {
        if ($category === null) {
            PHPUnit::assertEmpty(
                $this->records,
                'CapturePolicy was invoked unexpectedly.',
            );

            return;
        }

        PHPUnit::assertEmpty(
            $this->recordsFor($category),
            "CapturePolicy was invoked for category [{$category}] unexpectedly.",
        );
    }

    protected function record(string $category, ?RunContext $context, ?Actor $actor): CaptureDecision
    {
        $decision = $this->delegate !== null
            ? $this->delegate->{$category === 'active_context' ? 'activeContext' : $category}($context, $actor)
            : CaptureDecision::Full;

        $this->records[] = [
            'category' => $category,
            'context' => $context,
            'actor' => $actor,
            'decision' => $decision,
        ];

        return $decision;
    }
}
