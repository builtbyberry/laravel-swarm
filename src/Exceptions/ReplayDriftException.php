<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

/**
 * Raised when durable replay of a completed step produces tool calls that
 * diverge from the canonical snapshot record.
 *
 * Replay is the compliance-grade audit guarantee: the same step, replayed
 * later, must produce the same observable behaviour. If it doesn't —
 * different tools called, different arguments, different result ordering —
 * the run is no longer audit-defensible and the operator needs to know
 * immediately. Silent drift is the worst failure mode for regulated
 * workloads, so this exception is hard-failure by design (matches the
 * stance taken for the canonical-record guard in
 * {@see SnapshotFrozenException}).
 *
 * The structured diff payload is preserved on the exception so listeners can
 * forward it to audit sinks before the run aborts.
 */
class ReplayDriftException extends SwarmException
{
    /**
     * @param  array<int, array<string, mixed>>  $recorded
     * @param  array<int, array<string, mixed>>  $observed
     */
    public function __construct(
        public readonly string $runId,
        public readonly int $stepIndex,
        public readonly array $recorded,
        public readonly array $observed,
        string $message = '',
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf(
                'Durable replay produced tool calls that diverge from the canonical '
                .'snapshot for run [%s] step [%d]. Recorded %d call(s), observed %d.',
                $runId,
                $stepIndex,
                count($recorded),
                count($observed),
            ),
        );
    }
}
