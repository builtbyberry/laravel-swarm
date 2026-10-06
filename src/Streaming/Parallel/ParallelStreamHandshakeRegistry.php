<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Parallel;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

/** @internal */
final class ParallelStreamHandshakeRegistry
{
    /** @var array<string, true> */
    private array $authenticated = [];

    /** @param list<string> $branchIds */
    public function __construct(
        private string $token,
        private array $branchIds,
    ) {}

    /** @param array<string, mixed> $frame */
    public function accept(array $frame): string
    {
        $branchId = is_string($frame['branch_id'] ?? null) ? $frame['branch_id'] : '';
        if (($frame['v'] ?? null) !== ParallelStreamProtocol::VERSION
            || ! hash_equals($this->token, is_string($frame['token'] ?? null) ? $frame['token'] : '')
            || ($frame['type'] ?? null) !== 'hello'
            || ! in_array($branchId, $this->branchIds, true)) {
            throw new SwarmException('Parallel stream transport rejected an unauthenticated branch connection.');
        }
        if (isset($this->authenticated[$branchId])) {
            throw new SwarmException("Parallel stream transport rejected duplicate branch [{$branchId}].");
        }

        $this->authenticated[$branchId] = true;

        return $branchId;
    }
}
