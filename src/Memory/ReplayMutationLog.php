<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

/**
 * Ordered Run-scope mutations produced while an invocation replays against a
 * frozen memory snapshot.
 *
 * @internal
 */
final class ReplayMutationLog
{
    /**
     * @var array<int, array{op: 'put', scope_id: string, key: string, value: mixed, metadata: array<string, mixed>}|array{op: 'forget', scope_id: string, key: string}>
     */
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

    /**
     * @return array<int, array{op: 'put', scope_id: string, key: string, value: mixed, metadata: array<string, mixed>}|array{op: 'forget', scope_id: string, key: string}>
     */
    public function toArray(): array
    {
        return $this->operations;
    }

    /**
     * @param  array<int, array{op: 'put', scope_id: string, key: string, value: mixed, metadata: array<string, mixed>}|array{op: 'forget', scope_id: string, key: string}>  $operations
     */
    public static function fromArray(array $operations): self
    {
        $log = new self;
        $log->operations = array_values($operations);

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
