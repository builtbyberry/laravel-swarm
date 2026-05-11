<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

/**
 * Intentional policy block from a guardrail (not a retryable infrastructure failure).
 */
final class GuardrailViolation extends SwarmException
{
    /**
     * @param  array<string, mixed>  $metadata  Safe, operator-facing metadata only (may be persisted or emitted).
     */
    public function __construct(
        public readonly string $policyCode,
        public readonly string $reason,
        public readonly array $metadata = [],
        public readonly ?string $scope = null,
    ) {
        parent::__construct($reason);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function block(string $policyCode, string $reason, array $metadata = [], ?string $scope = null): self
    {
        return new self($policyCode, $reason, $metadata, $scope);
    }

    /**
     * Metadata merged into run context when persisting failures (safe subset).
     *
     * @return array<string, mixed>
     */
    public function safeContextMetadata(): array
    {
        $base = [
            'guardrail_code' => $this->policyCode,
            'guardrail_scope' => $this->scope,
        ];

        if ($this->metadata !== []) {
            $base['guardrail_metadata'] = $this->metadata;
        }

        return array_filter($base, static fn (mixed $v): bool => $v !== null);
    }
}
