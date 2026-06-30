<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Attributes;

use Attribute;
use BuiltByBerry\LaravelSwarm\Runners\SwarmAttributeResolver;

/**
 * Opt a swarm class into durable per-node streaming: when present, a durable run of
 * this swarm streams each node's events into the append-only causal log instead of
 * only producing one blocking `prompt()` response per node (#298/#310).
 *
 * The decision is resolved once and PINNED onto the durable run row at run-start
 * ({@see SwarmAttributeResolver::resolveDurableStreaming()}),
 * so every resume reads the value the run started with — a swarm streams (or does
 * not) for its entire life regardless of a mid-run code redeploy. A swarm without
 * the attribute never writes a stream event to the causal log.
 *
 * A bare `#[DurableStreaming]` opts in; `#[DurableStreaming(false)]` explicitly opts
 * out (useful to override a base class that opts in). Operators can additionally
 * disable durable streaming fleet-wide at runtime with the
 * `swarm.durable.streaming_enabled` kill-switch without redeploying.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DurableStreaming
{
    public function __construct(public readonly bool $enabled = true) {}
}
