<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

/**
 * Derived CLI label for coordinated queued hierarchical parallel runs (does not affect persisted status filters).
 */
final class SwarmRunPhase
{
    /**
     * @param  array<string, mixed>  $run
     */
    public static function cliLabel(array $run): string
    {
        $topology = (string) ($run['topology'] ?? '');
        $status = (string) ($run['status'] ?? '');
        $metadata = $run['metadata'] ?? [];

        if (! is_array($metadata)) {
            return '—';
        }

        $waitingParallel = filter_var($metadata['queue_hierarchical_waiting_parallel'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($status === 'waiting' && $topology === 'hierarchical' && $waitingParallel) {
            return 'parallel_join';
        }

        return '—';
    }
}
