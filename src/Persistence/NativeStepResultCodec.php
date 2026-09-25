<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use JsonException;

/** Seals and safely decodes the versioned native-result envelope. @internal */
final class NativeStepResultCodec
{
    private const MAX_REASONS = 16;

    private const MAX_REASON_BYTES = 64;

    /** @var list<string> */
    private const USAGE_KEYS = ['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'];

    public function __construct(private SwarmPersistenceCipher $cipher, private ConfigRepository $config) {}

    public function encode(?NativeStepResult $result): ?string
    {
        if ($result === null || $result->status === NativeStepResult::OMITTED) {
            return null;
        }

        $payload = $result->toArray();
        $failure = $this->validationFailure($payload);
        if ($failure !== null) {
            $payload = NativeStepResult::unavailable([$failure])->toArray();
        }

        return $this->cipher->seal(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function decode(mixed $value, ?string $status = null): NativeStepResult
    {
        if ($status === NativeStepResult::OMITTED) {
            return NativeStepResult::omitted();
        }
        if ($value === null) {
            return NativeStepResult::unavailable(['legacy']);
        }
        if (! is_string($value)) {
            return NativeStepResult::unavailable(['malformed']);
        }

        [$plain, $available] = $this->cipher->openForDisplay($value);
        if (! $available) {
            return NativeStepResult::unavailable(['decrypt_failed']);
        }
        if (! is_string($plain) || strlen($plain) > $this->limit('max_bytes', 262144, 16777216)) {
            return NativeStepResult::unavailable(['limit']);
        }

        try {
            $decodeDepth = max(1, min(136, $this->limit('max_structured_depth', 32, 128) + 8));
            $payload = json_decode($plain, true, $decodeDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return NativeStepResult::unavailable(['malformed']);
        }

        if (! is_array($payload)) {
            return NativeStepResult::unavailable(['malformed']);
        }

        $failure = $this->validationFailure($payload);

        return $failure === null
            ? NativeStepResult::fromArray($payload)
            : NativeStepResult::unavailable([$failure]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sealPayload(array $payload): array
    {
        $status = is_string($payload['native_result_status'] ?? null) ? $payload['native_result_status'] : null;
        $native = is_array($payload['native_result'] ?? null) ? NativeStepResult::fromArray($payload['native_result']) : null;
        $status ??= $native?->status;
        unset($payload['native_result']);
        if ($status !== null) {
            $payload['native_result_status'] = $status;
        }
        if ($native !== null && $status !== NativeStepResult::OMITTED) {
            $payload['native_result'] = $this->encode($native);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function openPayload(array $payload): array
    {
        if (isset($payload['type']) && $payload['type'] !== 'swarm_step_end') {
            return $payload;
        }

        $status = is_string($payload['native_result_status'] ?? null) ? $payload['native_result_status'] : null;
        if (! array_key_exists('native_result', $payload) && $status === null) {
            $payload['native_result'] = NativeStepResult::unavailable(['legacy'])->toArray();
            $payload['native_result_status'] = NativeStepResult::UNAVAILABLE;

            return $payload;
        }
        $native = $this->decode($payload['native_result'] ?? null, $status);
        $payload['native_result'] = $native->toArray();
        $payload['native_result_status'] = $native->status;

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function validationFailure(array $payload): ?string
    {
        $allowed = ['format_version', 'status', 'reasons', 'structured', 'reasoning', 'provider', 'model', 'invocation_id', 'conversation_id', 'user_message_id', 'assistant_message_id', 'generation_steps', 'tools'];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            return 'malformed';
        }
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'malformed';
        }
        if (strlen($encoded) > $this->limit('max_bytes', 262144, 16777216)) {
            return 'limit';
        }

        $reasons = $payload['reasons'] ?? [];
        if (! is_array($reasons) || ! array_is_list($reasons)) {
            return 'malformed';
        }
        if (count($reasons) > self::MAX_REASONS) {
            return 'limit';
        }
        foreach ($reasons as $reason) {
            if (! is_string($reason) || $reason === '') {
                return 'malformed';
            }
            if (strlen($reason) > self::MAX_REASON_BYTES) {
                return 'limit';
            }
        }

        $status = $payload['status'] ?? null;
        if (in_array($status, [NativeStepResult::OMITTED, NativeStepResult::UNAVAILABLE], true)
            && array_diff(array_keys($payload), ['format_version', 'status', 'reasons']) !== []) {
            return 'malformed';
        }
        if ($status === NativeStepResult::REDACTED
            && array_diff(array_keys($payload), ['format_version', 'status', 'reasons', 'provider', 'model', 'invocation_id', 'generation_steps', 'tools']) !== []) {
            return 'malformed';
        }

        $generationSteps = $payload['generation_steps'] ?? [];
        $tools = $payload['tools'] ?? [];
        if (! is_array($generationSteps) || ! array_is_list($generationSteps)
            || ! is_array($tools) || ! array_is_list($tools)) {
            return 'malformed';
        }
        if (count($generationSteps) > $this->limit('max_generation_steps', 64, 4096)
            || count($tools) > $this->limit('max_tool_statuses', 256, 4096)) {
            return 'limit';
        }
        foreach ([...$generationSteps, ...$tools] as $entry) {
            if (! is_array($entry)) {
                return 'malformed';
            }
        }
        $generationAllowed = ['text', 'structured', 'reasoning', 'finish_reason', 'provider', 'model', 'usage'];
        foreach ($generationSteps as $step) {
            if (array_diff(array_keys($step), $generationAllowed) !== []) {
                return 'malformed';
            }
            if ($status === NativeStepResult::REDACTED
                && array_diff(array_keys($step), ['finish_reason', 'provider', 'model', 'usage']) !== []) {
                return 'malformed';
            }
            $usage = $step['usage'] ?? null;
            if ($usage !== null) {
                if (! is_array($usage) || array_diff(array_keys($usage), self::USAGE_KEYS) !== []) {
                    return 'malformed';
                }
                foreach ($usage as $value) {
                    if (! is_int($value) && $value !== null) {
                        return 'malformed';
                    }
                }
            }
        }
        $toolAllowed = ['call_id', 'result_id', 'name', 'status'];
        foreach ($tools as $tool) {
            if (array_diff(array_keys($tool), $toolAllowed) !== []) {
                return 'malformed';
            }
        }

        $reasoningLimit = $this->limit('max_reasoning_bytes', 65536, 1048576);
        if (is_string($payload['reasoning'] ?? null) && strlen($payload['reasoning']) > $reasoningLimit) {
            return 'limit';
        }
        foreach ($generationSteps as $step) {
            if (is_string($step['reasoning'] ?? null) && strlen($step['reasoning']) > $reasoningLimit) {
                return 'limit';
            }
        }

        $items = 0;
        $maxItems = $this->limit('max_structured_items', 4096, 100000);
        $maxDepth = $this->limit('max_structured_depth', 32, 128);
        foreach ([$payload['structured'] ?? null, ...array_map(static fn (array $step): mixed => $step['structured'] ?? null, $generationSteps)] as $structured) {
            if ($structured !== null && ! is_array($structured)) {
                return 'malformed';
            }
            if (is_array($structured) && ! $this->structuredWithinLimits($structured, $items, $maxItems, $maxDepth)) {
                return 'limit';
            }
        }

        return null;
    }

    /** @param array<mixed> $value */
    private function structuredWithinLimits(array $value, int &$items, int $maxItems, int $maxDepth, int $depth = 0): bool
    {
        if ($depth >= $maxDepth) {
            return $value === [];
        }
        foreach ($value as $item) {
            if (++$items > $maxItems) {
                return false;
            }
            if (is_array($item) && ! $this->structuredWithinLimits($item, $items, $maxItems, $maxDepth, $depth + 1)) {
                return false;
            }
            if (! is_array($item) && ! is_null($item) && ! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }

    private function limit(string $key, int $default, int $hard): int
    {
        $value = (int) $this->config->get('swarm.native_results.'.$key, $default);

        return max($key === 'max_bytes' ? 256 : 1, min($value, $hard));
    }
}
