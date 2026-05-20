<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing\Audit;

use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use Illuminate\Testing\Assert as PHPUnit;
use Throwable;

/**
 * Test double for {@see SinkFailureHandler} that records every routed
 * sink (or signing) failure.
 *
 * Wraps an optional delegate handler and forwards each handle() call to it,
 * recording the category, payload, exception, and routing decision the
 * dispatcher acted on. When no delegate is supplied, every failure routes to
 * {@see SinkFailureDecision::Swallow} so the recorder works as a stand-alone
 * handler without further setup.
 *
 * SwarmFake installs this via
 * {@see SwarmFake::interceptSinkFailureHandler()},
 * which swaps the container binding only. The dispatcher resolves this
 * recorder during the real run and records the failure path without
 * SwarmFake itself ever invoking the dispatcher.
 */
class RecordingSinkFailureHandler implements SinkFailureHandler
{
    /**
     * @var array<int, array{sink: SwarmAuditSink, category: string, payload: array<string, mixed>, exception: Throwable, decision: SinkFailureDecision}>
     */
    protected array $records = [];

    public function __construct(
        protected ?SinkFailureHandler $delegate = null,
        protected SinkFailureDecision $defaultDecision = SinkFailureDecision::Swallow,
    ) {}

    public function handle(
        SwarmAuditSink $sink,
        string $category,
        array $payload,
        Throwable $exception,
    ): SinkFailureDecision {
        $decision = $this->delegate !== null
            ? $this->delegate->handle($sink, $category, $payload, $exception)
            : $this->defaultDecision;

        $this->records[] = [
            'sink' => $sink,
            'category' => $category,
            'payload' => $payload,
            'exception' => $exception,
            'decision' => $decision,
        ];

        return $decision;
    }

    /**
     * Every recorded failure routing in the order it was handled.
     *
     * @return array<int, array{sink: SwarmAuditSink, category: string, payload: array<string, mixed>, exception: Throwable, decision: SinkFailureDecision}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Records for a single category.
     *
     * @return array<int, array{sink: SwarmAuditSink, category: string, payload: array<string, mixed>, exception: Throwable, decision: SinkFailureDecision}>
     */
    public function recordsFor(string $category): array
    {
        return array_values(array_filter(
            $this->records,
            fn (array $record): bool => $record['category'] === $category,
        ));
    }

    /**
     * Assert at least one sink failure was routed.
     *
     * Pass a callable matcher to inspect a recorded entry (sink, category,
     * payload, exception, decision). Without a matcher the assertion only
     * verifies the handler was invoked at least once.
     */
    public function assertSinkFailureRouted(?callable $matcher = null): void
    {
        if ($matcher === null) {
            PHPUnit::assertNotEmpty(
                $this->records,
                'SinkFailureHandler was not invoked.',
            );

            return;
        }

        PHPUnit::assertTrue(
            collect($this->records)->contains(fn (array $record): bool => (bool) $matcher($record)),
            'SinkFailureHandler did not record a routing matching the expected matcher.',
        );
    }

    /**
     * Assert at least one sink failure was routed with the given decision.
     */
    public function assertSinkFailureRoutedAs(SinkFailureDecision $decision, ?string $category = null): void
    {
        $records = $category === null
            ? $this->records
            : $this->recordsFor($category);

        $label = $category === null
            ? 'across any category'
            : "for category [{$category}]";

        PHPUnit::assertTrue(
            collect($records)->contains(fn (array $record): bool => $record['decision'] === $decision),
            "SinkFailureHandler did not return [{$decision->name}] {$label}.",
        );
    }

    /**
     * Assert no sink failure was routed at all (optionally for a single
     * category).
     */
    public function assertNeverSinkFailure(?string $category = null): void
    {
        if ($category === null) {
            PHPUnit::assertEmpty(
                $this->records,
                'SinkFailureHandler was invoked unexpectedly.',
            );

            return;
        }

        PHPUnit::assertEmpty(
            $this->recordsFor($category),
            "SinkFailureHandler was invoked for category [{$category}] unexpectedly.",
        );
    }
}
