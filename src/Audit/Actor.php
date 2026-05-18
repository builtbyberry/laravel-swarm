<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Identity binding for a swarm run.
 *
 * An Actor represents who or what initiated a run. Resolved once at run entry
 * via ActorResolver (or bound explicitly via RunContext::withActor) and stored
 * in RunContext metadata under the reserved "actor" key. Audit sinks receive
 * the actor on every evidence record alongside allowlisted metadata.
 *
 * Values are immutable. Use the named constructors for the common cases:
 *   Actor::system('cron:nightly');
 *   Actor::user($authenticatable);
 *
 * For arbitrary input shapes (Authenticatable, string shorthand, existing
 * Actor), use Actor::fromAny().
 */
final readonly class Actor
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $name = null,
        public array $metadata = [],
    ) {
        if ($id === '') {
            throw new SwarmException('Actor id must be a non-empty string.');
        }

        if ($type === '') {
            throw new SwarmException('Actor type must be a non-empty string.');
        }
    }

    public static function system(string $id = 'system', ?string $name = null): self
    {
        return new self(id: $id, type: 'system', name: $name);
    }

    public static function user(Authenticatable $user, ?string $name = null): self
    {
        return new self(
            id: (string) $user->getAuthIdentifier(),
            type: 'user',
            name: $name,
        );
    }

    /**
     * Normalize any supported actor input into an Actor instance.
     *
     * String shorthand: "type:id" splits on the first colon (e.g. "api_token:abc").
     * Strings without a colon are treated as a system id (e.g. "billing-cron").
     */
    public static function fromAny(self|Authenticatable|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof Authenticatable) {
            return self::user($value);
        }

        if (str_contains($value, ':')) {
            [$type, $id] = explode(':', $value, 2);

            return new self(id: $id, type: $type);
        }

        return self::system($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        if (! isset($payload['id']) || ! is_string($payload['id'])) {
            throw new SwarmException('Actor array must contain a string [id].');
        }

        if (! isset($payload['type']) || ! is_string($payload['type'])) {
            throw new SwarmException('Actor array must contain a string [type].');
        }

        $name = $payload['name'] ?? null;

        if ($name !== null && ! is_string($name)) {
            throw new SwarmException('Actor [name] must be a string or null.');
        }

        $metadata = $payload['metadata'] ?? [];

        if (! is_array($metadata)) {
            throw new SwarmException('Actor [metadata] must be an array.');
        }

        return new self(
            id: $payload['id'],
            type: $payload['type'],
            name: $name,
            metadata: $metadata,
        );
    }

    /**
     * @return array{id: string, type: string, name: ?string, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'metadata' => $this->metadata,
        ];
    }
}
