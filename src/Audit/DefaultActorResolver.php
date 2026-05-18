<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\ActorResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Default ActorResolver: Context::get('swarm:actor') wins, falls back to the
 * authenticated user, then null.
 *
 * Context bindings survive queue serialization, so callers that wrap a dispatch
 * in Context::add('swarm:actor', $actor) keep actor attribution across worker
 * boundaries. The auth() fallback only works in-request; queued runs that need
 * attribution must bind explicitly via RunContext::withActor() or Context.
 */
class DefaultActorResolver implements ActorResolver
{
    public function __construct(
        protected ?AuthFactory $auth = null,
    ) {}

    public function resolve(): ?Actor
    {
        return $this->resolveFromContext() ?? $this->resolveFromAuth();
    }

    protected function resolveFromContext(): ?Actor
    {
        try {
            $value = Context::get('swarm:actor');
        } catch (Throwable) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        if ($value instanceof Actor) {
            return $value;
        }

        if ($value instanceof Authenticatable) {
            return Actor::user($value);
        }

        if (is_string($value) && $value !== '') {
            return Actor::fromAny($value);
        }

        if (is_array($value) && isset($value['id'], $value['type'])) {
            return Actor::fromArray($value);
        }

        return null;
    }

    protected function resolveFromAuth(): ?Actor
    {
        if ($this->auth === null) {
            return null;
        }

        try {
            $user = $this->auth->guard()->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof Authenticatable ? Actor::user($user) : null;
    }
}
