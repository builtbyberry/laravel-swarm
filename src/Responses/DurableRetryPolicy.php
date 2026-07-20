<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

class DurableRetryPolicy
{
    /**
     * @param  array<int, int>  $backoffSeconds
     * @param  array<int, string>  $nonRetryable
     */
    public function __construct(
        public readonly int $maxAttempts = 1,
        public readonly array $backoffSeconds = [],
        public readonly array $nonRetryable = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            maxAttempts: max(1, (int) ($payload['max_attempts'] ?? 1)),
            backoffSeconds: collect(is_array($payload['backoff_seconds'] ?? null) ? $payload['backoff_seconds'] : [])->map(static fn (mixed $value): int => (int) $value)->values()->all(),
            nonRetryable: collect(is_array($payload['non_retryable'] ?? null) ? $payload['non_retryable'] : [])->filter(static fn (mixed $value): bool => is_string($value))->values()->all(),
        );
    }

    /**
     * @return array{max_attempts: int, backoff_seconds: array<int, int>, non_retryable: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'backoff_seconds' => $this->backoffSeconds,
            'non_retryable' => $this->nonRetryable,
        ];
    }

    public function delayForAttempt(int $attempt): int
    {
        if ($this->backoffSeconds === []) {
            return 0;
        }

        return $this->backoffSeconds[min(max($attempt - 1, 0), count($this->backoffSeconds) - 1)];
    }
}
