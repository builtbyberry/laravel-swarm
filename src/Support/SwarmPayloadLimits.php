<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use JsonException;

class SwarmPayloadLimits
{
    public function __construct(
        protected ConfigRepository $config,
    ) {}

    public function checkInput(string $input): void
    {
        $this->check($input, 'input', $this->configuredBytes('max_input_bytes'));
    }

    public function checkContextInput(RunContext $context): void
    {
        try {
            $payload = json_encode($context->toQueuePayload(), JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SwarmException('Swarm input context payload must be plain data that can be encoded as JSON.', previous: $exception);
        }

        $this->check($payload, 'input', $this->configuredBytes('max_input_bytes'));
    }

    public function output(string $output): PayloadLimitResult
    {
        return $this->check($output, 'output', $this->configuredBytes('max_output_bytes'));
    }

    public function response(SwarmResponse $response): SwarmResponse
    {
        $output = $this->output($response->output);
        $context = $response->context;

        if ($context !== null && $output->metadata !== []) {
            $data = $context->data;

            if (array_key_exists('last_output', $data)) {
                $data['last_output'] = $output->value;
            }

            $context = new RunContext(
                runId: $context->runId,
                input: $context->input,
                data: $data,
                metadata: array_merge($context->metadata, $output->metadata),
                artifacts: $context->artifacts,
            );
        }

        return new SwarmResponse(
            output: $output->value,
            steps: $response->steps,
            usage: $response->usage,
            context: $context,
            artifacts: $response->artifacts,
            metadata: array_merge($response->metadata, $output->metadata),
        );
    }

    protected function check(string $payload, string $kind, ?int $limit): PayloadLimitResult
    {
        if ($limit === null) {
            return new PayloadLimitResult($payload);
        }

        $bytes = strlen($payload);

        if ($bytes <= $limit) {
            return new PayloadLimitResult($payload);
        }

        $overflow = (string) $this->config->get('swarm.limits.overflow', 'fail');

        if ($overflow === 'truncate') {
            $truncated = $this->truncateUtf8($payload, $limit);

            return new PayloadLimitResult($truncated, [
                "{$kind}_truncated" => true,
                "{$kind}_original_bytes" => $bytes,
                "{$kind}_stored_bytes" => strlen($truncated),
            ]);
        }

        if ($overflow !== 'fail') {
            throw new SwarmException("Invalid swarm payload overflow strategy [{$overflow}]. Supported strategies: fail, truncate.");
        }

        throw new SwarmException("Swarm {$kind} payload is {$bytes} bytes, which exceeds the configured {$limit} byte limit.");
    }

    protected function configuredBytes(string $key): ?int
    {
        $value = $this->config->get("swarm.limits.{$key}");

        if ($value === null || $value === '') {
            return null;
        }

        $bytes = (int) $value;

        if ($bytes <= 0) {
            throw new SwarmException("Swarm payload limit [{$key}] must be a positive integer or null.");
        }

        return $bytes;
    }

    protected function truncateUtf8(string $payload, int $limit): string
    {
        if (function_exists('mb_strcut')) {
            return mb_strcut($payload, 0, $limit, 'UTF-8');
        }

        $truncated = substr($payload, 0, $limit);

        while ($truncated !== '' && preg_match('//u', $truncated) !== 1) {
            $truncated = substr($truncated, 0, -1);
        }

        return $truncated;
    }
}
