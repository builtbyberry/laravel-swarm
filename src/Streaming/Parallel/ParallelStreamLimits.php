<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Parallel;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/** @internal */
final class ParallelStreamLimits
{
    public function __construct(private ConfigRepository $config) {}

    /** @return array{max_branches: int, max_frame_bytes: int, cancel_grace_milliseconds: int} */
    public function resolve(): array
    {
        return [
            'max_branches' => $this->boundedInteger('swarm.streaming.parallel.max_branches', 32, 1, 256),
            'max_frame_bytes' => $this->boundedInteger('swarm.streaming.parallel.max_frame_bytes', 2_097_152, 1_024, 4_194_304),
            'cancel_grace_milliseconds' => $this->boundedInteger('swarm.streaming.parallel.cancel_grace_milliseconds', 250, 0, 10_000),
        ];
    }

    private function boundedInteger(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = (int) $this->config->get($key, $default);
        if ($value < $minimum || $value > $maximum) {
            throw new SwarmException("Invalid [{$key}] value [{$value}]; expected {$minimum}..{$maximum}.");
        }

        return $value;
    }
}
