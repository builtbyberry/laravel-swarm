<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use JsonException;

/** Bounded JSON data with explicit availability; never calls object serialization hooks. */
final readonly class ProviderToolData
{
    public const MAX_BYTES = 1048576;

    public const MAX_DEPTH = 64;

    /** @param array<array-key, mixed> $data
     * @param  list<string>  $reasons
     */
    private function __construct(public array $data, public string $status, public array $reasons = []) {}

    public static function withheld(string $status, ?string $reason = null): self
    {
        if (! in_array($status, ['partial', 'redacted', 'omitted', 'unavailable', 'unknown'], true)
            || ($reason !== null && ! in_array($reason, ['limit', 'malformed', 'unsupported_type', 'decrypt_failed'], true))
            || ($status === 'partial' && $reason !== 'limit')) {
            return new self([], 'unavailable', ['malformed']);
        }

        return new self([], $status, $reason === null ? [] : [$reason]);
    }

    /** @param array<array-key, mixed> $data */
    public static function capture(array $data, int $maxBytes = self::MAX_BYTES, int $maxDepth = self::MAX_DEPTH): self
    {
        $remaining = max(0, min(self::MAX_BYTES, $maxBytes));
        $reason = null;
        $copy = self::copy($data, $remaining, max(0, min(self::MAX_DEPTH, $maxDepth)), $reason);

        return $reason === null && is_array($copy)
            ? new self($copy, 'available')
            : self::withheld($reason === 'limit' ? 'partial' : 'unavailable', $reason ?? 'malformed');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['data_status' => $this->status, 'data_reasons' => $this->reasons]
            + ($this->status === 'omitted' ? [] : ['data' => $this->data]);
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (! array_key_exists('data_status', $payload)) {
            return self::withheld('unknown');
        }
        $status = $payload['data_status'];
        $reasons = $payload['data_reasons'] ?? null;
        if (! in_array($status, ['available', 'partial', 'redacted', 'omitted', 'unavailable', 'unknown'], true)
            || ! is_array($reasons) || ! array_is_list($reasons) || count($reasons) > 1
            || ($reasons !== [] && ! in_array($reasons[0], ['limit', 'malformed', 'unsupported_type', 'decrypt_failed'], true))
            || ($status === 'available' && $reasons !== []) || ($status === 'partial' && $reasons !== ['limit'])
            || ($status !== 'omitted' && ! is_array($payload['data'] ?? null))
            || ($status !== 'available' && ($payload['data'] ?? []) !== [])) {
            return self::withheld('unavailable', 'malformed');
        }

        return $status === 'available' ? self::capture($payload['data']) : new self([], $status, $reasons);
    }

    private static function copy(mixed $value, int &$remaining, int $depth, ?string &$reason): mixed
    {
        if ($remaining < 1 || $depth < 0) {
            $reason = 'limit';

            return null;
        }
        if (is_array($value)) {
            $remaining -= 2;
            $list = array_is_list($value);
            $copy = [];
            foreach ($value as $key => $item) {
                if ($copy !== []) {
                    $remaining--;
                }
                if (! $list) {
                    self::copy((string) $key, $remaining, $depth, $reason);
                    $remaining--;
                }
                if ($reason !== null || $remaining < 1) {
                    $reason ??= 'limit';

                    return null;
                }
                $copy[$key] = self::copy($item, $remaining, $depth - 1, $reason);
                if ($reason !== null) {
                    return null;
                }
            }
            if ($remaining < 0) {
                $reason = 'limit';
            }

            return $copy;
        }
        if (! is_scalar($value) && $value !== null) {
            $reason = 'unsupported_type';

            return null;
        }
        if (is_string($value) && strlen($value) > $remaining) {
            $reason = 'limit';

            return null;
        }
        try {
            $remaining -= strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        } catch (JsonException) {
            $reason = 'malformed';
        }
        if ($remaining < 0) {
            $reason = 'limit';
        }

        return $value;
    }
}
