<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

/**
 * Immutable view of the swarm run currently executing an agent, as published by
 * {@see ActiveRunContext}.
 *
 * Carries only serializable identity — the run id, the swarm class-string, and
 * the serialized {@see RunContext} payload — so it survives queue and
 * concurrency-process boundaries. The {@see RunContext} is rehydrated lazily on
 * first {@see context()} call.
 *
 * @internal
 */
final class ActiveRunRecord
{
    private ?RunContext $context = null;

    /**
     * @param  array<string, mixed>  $contextPayload
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $swarmClass,
        private readonly array $contextPayload,
    ) {}

    public function context(): RunContext
    {
        return $this->context ??= RunContext::fromPayload($this->contextPayload, $this->runId);
    }
}
