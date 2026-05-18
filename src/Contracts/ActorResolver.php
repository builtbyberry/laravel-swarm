<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Audit\Actor;

/**
 * Resolves the Actor for a swarm run when none is explicitly bound on the RunContext.
 *
 * Bind a custom resolver in the service container to source actor identity from
 * application-specific places (request state, API token introspection, signed
 * job payloads, etc.). Resolution happens once at run entry; the resulting
 * Actor is stored in RunContext::metadata under the reserved "actor" key.
 *
 * The default binding (DefaultActorResolver) reads Context::get('swarm:actor')
 * first, then falls back to the authenticated user, then returns null.
 */
interface ActorResolver
{
    public function resolve(): ?Actor;
}
