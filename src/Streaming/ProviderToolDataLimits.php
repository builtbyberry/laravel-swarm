<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use BuiltByBerry\LaravelSwarm\Responses\ProviderToolData;
use Illuminate\Contracts\Config\Repository;

/** @internal */
final class ProviderToolDataLimits
{
    public function __construct(private Repository $config) {}

    /** @param array<array-key, mixed> $data */
    public function capture(array $data, int &$stepBytes): ProviderToolData
    {
        $eventLimit = $this->bound('max_event_bytes', 65536, ProviderToolData::MAX_BYTES);
        $stepLimit = $this->bound('max_step_bytes', 262144, ProviderToolData::MAX_BYTES * 16);
        $result = ProviderToolData::capture($data, min($eventLimit, max(0, $stepLimit - $stepBytes)),
            $this->bound('max_depth', 32, ProviderToolData::MAX_DEPTH));
        if ($result->status === 'available') {
            $stepBytes += strlen(json_encode($result->data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        }

        return $result;
    }

    private function bound(string $key, int $default, int $ceiling): int
    {
        $value = $this->config->get('swarm.provider_tools.'.$key, $default);

        return is_int($value) ? max(0, min($ceiling, $value)) : $default;
    }
}
