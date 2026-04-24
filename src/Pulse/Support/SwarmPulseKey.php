<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Pulse\Support;

class SwarmPulseKey
{
    public static function runStatus(string $swarmClass, string $topology, string $status): string
    {
        return implode('|', ['swarm_run', $swarmClass, $topology, $status]);
    }

    public static function runDuration(string $swarmClass, string $topology): string
    {
        return implode('|', ['swarm_run_duration', $swarmClass, $topology]);
    }

    public static function stepDuration(string $swarmClass, string $topology, string $agentClass): string
    {
        return implode('|', ['swarm_step_duration', $swarmClass, $topology, $agentClass]);
    }

    /**
     * @return array{type: string, swarmClass: string, topology: string, status: string}
     */
    public static function parseRunStatus(string $key): array
    {
        [$type, $swarmClass, $topology, $status] = explode('|', $key, 4);

        return compact('type', 'swarmClass', 'topology', 'status');
    }

    /**
     * @return array{type: string, swarmClass: string, topology: string}
     */
    public static function parseRunDuration(string $key): array
    {
        [$type, $swarmClass, $topology] = explode('|', $key, 3);

        return compact('type', 'swarmClass', 'topology');
    }

    /**
     * @return array{type: string, swarmClass: string, topology: string, agentClass: string}
     */
    public static function parseStepDuration(string $key): array
    {
        [$type, $swarmClass, $topology, $agentClass] = explode('|', $key, 4);

        return compact('type', 'swarmClass', 'topology', 'agentClass');
    }
}
