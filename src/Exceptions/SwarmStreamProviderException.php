<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

use Throwable;

class SwarmStreamProviderException extends SwarmException
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $message,
        public readonly string $eventId,
        public readonly ?string $invocationId,
        public readonly bool $recoverable,
        public readonly array $metadata,
        public readonly int $timestamp,
        public readonly ?string $providerErrorType = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
