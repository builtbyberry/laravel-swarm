<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;

class DurablePayloadCapture
{
    public function __construct(
        protected SwarmCapture $capture,
    ) {}

    public function payload(mixed $payload): mixed
    {
        if ($this->capture->capturesInputs() && $this->capture->capturesOutputs()) {
            return $payload;
        }

        if (is_array($payload)) {
            return $this->redactArray($payload);
        }

        return SwarmCapture::REDACTED;
    }

    /**
     * @return array<string, mixed>
     */
    public function eventMetadata(RunContext $context): array
    {
        $metadata = $this->payload($context->metadata);

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    protected function redactArray(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $redacted[$key] = is_array($value) ? $this->redactArray($value) : SwarmCapture::REDACTED;
        }

        return $redacted;
    }
}
