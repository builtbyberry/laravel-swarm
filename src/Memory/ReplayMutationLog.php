<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

/**
 * Ordered Run-scope mutations produced while an invocation replays against a
 * frozen memory snapshot.
 *
 * @phpstan-import-type ReplayMutation from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 *
 * @internal
 */
final class ReplayMutationLog
{
    /** @var list<ReplayMutation> */
    private array $operations = [];

    private bool $applied = false;

    /** @param  array<string, mixed>  $metadata */
    public function put(string $scopeId, string $key, mixed $value, array $metadata = []): void
    {
        $this->operations[] = [
            'op' => 'put',
            'scope_id' => $scopeId,
            'key' => $key,
            'value' => $value,
            'metadata' => $metadata,
        ];
    }

    public function forget(string $scopeId, string $key): void
    {
        $this->operations[] = [
            'op' => 'forget',
            'scope_id' => $scopeId,
            'key' => $key,
        ];
    }

    /** @return list<ReplayMutation> */
    public function toArray(): array
    {
        return $this->operations;
    }

    /** @param  array<int, mixed>  $operations */
    public static function fromArray(array $operations): self
    {
        $log = new self;

        foreach ($operations as $index => $operation) {
            if (! is_array($operation)) {
                throw new SwarmException("Invalid replay memory mutation at index [{$index}]: row must be an array.");
            }

            $op = $operation['op'] ?? null;
            $scopeId = $operation['scope_id'] ?? null;
            $key = $operation['key'] ?? null;

            if (! in_array($op, ['put', 'forget'], true)) {
                throw new SwarmException("Invalid replay memory mutation at index [{$index}]: op must be exactly [put] or [forget].");
            }

            if (! is_string($scopeId) || $scopeId === '') {
                throw new SwarmException("Invalid replay memory mutation at index [{$index}]: scope_id must be a non-empty string.");
            }

            if (! is_string($key) || $key === '') {
                throw new SwarmException("Invalid replay memory mutation at index [{$index}]: key must be a non-empty string.");
            }

            if ($op === 'put' && (! array_key_exists('value', $operation) || ! is_array($operation['metadata'] ?? null))) {
                throw new SwarmException("Invalid replay memory mutation at index [{$index}]: put must contain a value and array metadata.");
            }

            /** @var ReplayMutation $operation */
            $log->operations[] = $operation;
        }

        return $log;
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    public function isApplied(): bool
    {
        return $this->applied;
    }

    public function markApplied(): void
    {
        $this->applied = true;
    }
}
