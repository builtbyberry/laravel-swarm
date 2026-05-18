<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

/**
 * Thrown at swarm run entry when swarm.audit.actor.required is true and no
 * Actor could be resolved.
 *
 * Set swarm.audit.actor.required=false to allow anonymous runs, or bind an
 * actor before dispatch via RunContext::withActor() or
 * Context::add('swarm:actor', $actor).
 */
class MissingActorException extends SwarmException {}
