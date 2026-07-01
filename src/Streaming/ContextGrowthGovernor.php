<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\GrowthPolicy;
use BuiltByBerry\LaravelSwarm\Exceptions\ContextBudgetExceededException;
use BuiltByBerry\LaravelSwarm\Runners\SwarmAttributeResolver;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Governs a streaming run's hot context growth against the operator budget (#288).
 *
 * Evaluated at each step boundary by the streaming runners. The working-set
 * measure is the count of stream events emitted in the **current stream segment**
 * — a cheap, in-process proxy for hot growth, not a durable run-global total: it
 * resets to zero when a run resumes in a fresh process, so the budget and hard-cap
 * are enforced per segment, not across the whole run history. That is a deliberate
 * trade (no persisted counter, no migration, idempotent on resume) consistent with
 * the hard-cap being best-effort governance rather than a correctness invariant.
 * The measure is compared to the operator-supplied budget and hard-cap, and the
 * author's declared {@see GrowthPolicy} rung is applied. The hard-cap clamps author
 * intent: a breach refuses regardless of the declared policy.
 *
 * Two design contracts hold this surface together:
 *
 *  - **Stateless / Octane-safe.** No mutable instance state. Per-run throttle
 *    memory is threaded in via `&$state` (a generator-local array owned by the
 *    caller), so two runs in one worker never share warn/nudge bookkeeping.
 *  - **Fail-safe.** The whole evaluation is wrapped: a throwing or mis-measuring
 *    policy degrades to no action and the run proceeds. The *only* intentional
 *    throw is {@see ContextBudgetExceededException} (the `refuse` rung or a
 *    hard-cap breach) — it is re-raised, never swallowed.
 *
 * Scope: the streaming substrate only. Non-streaming prompt() runs do not
 * accumulate a hot causal log and never reach this governor.
 *
 * @internal
 */
class ContextGrowthGovernor
{
    /** Upper bound on the backpressure delay, so an operator cannot wedge a run. */
    protected const MAX_BACKPRESSURE_DELAY_MS = 5_000;

    public function __construct(
        protected SwarmAttributeResolver $resolver,
        protected ConfigRepository $config,
        protected SwarmTelemetryDispatcher $telemetry,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Evaluate the growth policy for one step boundary.
     *
     * @param  array<string, bool>  $state  Per-run throttle memory (generator-local).
     *
     * @throws ContextBudgetExceededException when the declared `refuse` rung or the
     *                                        operator hard-cap is breached.
     */
    public function evaluate(Swarm $swarm, string $runId, int $workingSetEvents, array &$state): void
    {
        try {
            $this->doEvaluate($swarm, $runId, $workingSetEvents, $state);
        } catch (ContextBudgetExceededException $refusal) {
            throw $refusal;
        } catch (Throwable $exception) {
            // Fail-safe: the policy machinery must never wedge a healthy run.
            if (! ($state['growth_failed'] ?? false)) {
                $state['growth_failed'] = true;
                $this->safelyLog('warning', 'laravel-swarm: context-growth policy evaluation failed; defaulting to no action.', [
                    'run_id' => $runId,
                    'exception_class' => $exception::class,
                ]);
            }
        }
    }

    /**
     * @param  array<string, bool>  $state
     *
     * @throws ContextBudgetExceededException
     */
    protected function doEvaluate(Swarm $swarm, string $runId, int $workingSetEvents, array &$state): void
    {
        $budget = $this->configuredCount('budget_events');
        $hardCap = $this->configuredCount('hard_cap_events');

        // Inert until the operator supplies at least one threshold — the package
        // ships the machinery and the author's intent, never an imposed number.
        if ($budget === null && $hardCap === null) {
            return;
        }

        // Hard-cap clamp is author-independent and takes precedence over intent.
        if ($hardCap !== null && $workingSetEvents > $hardCap) {
            $policy = $this->resolver->resolveGrowthPolicy($swarm);
            $this->emitTelemetry($swarm, $runId, $workingSetEvents, $budget, $hardCap, $policy, GrowthPolicy::Refuse, 'hard_cap');
            $this->warnOnce($runId, $workingSetEvents, $hardCap, 'hard cap', $state);

            throw new ContextBudgetExceededException(sprintf(
                'Swarm run [%s] hot working set is %d events, exceeding the operator context-growth hard cap of %d events.',
                $runId,
                $workingSetEvents,
                $hardCap,
            ));
        }

        if ($budget === null || $workingSetEvents <= $budget) {
            return;
        }

        $policy = $this->resolver->resolveGrowthPolicy($swarm);

        // The author opted out; only the hard-cap (handled above) can act.
        if ($policy === GrowthPolicy::Ignore) {
            return;
        }

        $this->emitTelemetry($swarm, $runId, $workingSetEvents, $budget, $hardCap, $policy, $policy, 'budget');

        if ($policy === GrowthPolicy::Refuse) {
            $this->warnOnce($runId, $workingSetEvents, $budget, 'budget', $state);

            throw new ContextBudgetExceededException(sprintf(
                'Swarm run [%s] hot working set is %d events, exceeding the context-growth budget of %d events under a refuse policy.',
                $runId,
                $workingSetEvents,
                $budget,
            ));
        }

        // Cumulative non-refuse actions, escalating up to the declared ceiling.
        if ($policy->permits(GrowthPolicy::Warn)) {
            $this->warnOnce($runId, $workingSetEvents, $budget, 'budget', $state);
        }

        if ($policy->permits(GrowthPolicy::DegradeToCold)) {
            $this->warnDegradeToColdUnavailableOnce($runId, $state);
        }

        if ($policy->permits(GrowthPolicy::Backpressure)) {
            $this->backpressure();
        }
    }

    /**
     * @param  array<string, bool>  $state
     */
    protected function warnOnce(string $runId, int $workingSetEvents, int $threshold, string $kind, array &$state): void
    {
        if ($state['growth_warned'] ?? false) {
            return;
        }

        $state['growth_warned'] = true;

        $this->safelyLog('warning', 'laravel-swarm: streaming run hot working set exceeds its context-growth {kind}.', [
            'run_id' => $runId,
            'kind' => $kind,
            'working_set_events' => $workingSetEvents,
            'threshold_events' => $threshold,
        ]);
    }

    /**
     * Degrade-to-cold is inert for a live (non-durable) stream — warn once.
     *
     * This governor is evaluated ONLY by the live stream() runners
     * (SequentialStreamRunner, StaticHierarchicalStreamRunner), whose runs have no
     * swarm_durable_runs row and therefore no compaction lease anchor. Compaction
     * graduates a sealed hot prefix to cold storage for durable resume; there is
     * nothing to graduate for a transient live stream, and dispatching
     * CompactSwarmRun would silently no-op (SwarmCompactor::acquireLease updates
     * zero rows). A live stream's hot log is bounded by TTL via `swarm:prune`.
     *
     * We deliberately do NOT gate on the #[DurableStreaming] attribute: a swarm
     * can carry that attribute and still be invoked via live stream() with no
     * durable row, so the attribute would be a misleading "is this run durable"
     * signal and would re-introduce the silent no-op. The rung simply warns once
     * that cold graduation is unavailable here; any higher rung (backpressure)
     * still applies.
     *
     * @param  array<string, bool>  $state
     */
    protected function warnDegradeToColdUnavailableOnce(string $runId, array &$state): void
    {
        if ($state['growth_nudged'] ?? false) {
            return;
        }

        $state['growth_nudged'] = true;

        $this->safelyLog('warning', 'laravel-swarm: context-growth degrade-to-cold is inert for a live (non-durable) stream; hot events are bounded by swarm:prune (TTL). Use #[DurableStreaming] for durable per-node streaming with cold graduation.', [
            'run_id' => $runId,
        ]);
    }

    protected function backpressure(): void
    {
        $configured = $this->config->get('swarm.context_growth.backpressure_delay_ms', 250);
        $ms = is_numeric($configured) ? (int) $configured : 250;
        $ms = max(0, min($ms, self::MAX_BACKPRESSURE_DELAY_MS));

        if ($ms > 0) {
            usleep($ms * 1_000);
        }
    }

    protected function emitTelemetry(
        Swarm $swarm,
        string $runId,
        int $workingSetEvents,
        ?int $budget,
        ?int $hardCap,
        GrowthPolicy $declared,
        GrowthPolicy $action,
        string $trigger,
    ): void {
        try {
            $this->telemetry->emit('context_growth.action', [
                'run_id' => $runId,
                'swarm_class' => $swarm::class,
                'working_set_events' => $workingSetEvents,
                'budget_events' => $budget,
                'hard_cap_events' => $hardCap,
                'declared_policy' => $declared->value,
                'action' => $action->value,
                'trigger' => $trigger,
            ]);
        } catch (Throwable) {
            // The dispatcher is already failure-isolated; this is belt-and-braces
            // so telemetry can never abort an evaluation.
        }
    }

    protected function configuredCount(string $key): ?int
    {
        $value = $this->config->get("swarm.context_growth.{$key}");

        if ($value === null || $value === '') {
            return null;
        }

        $count = (int) $value;

        // Lenient + fail-safe: a non-positive value disables the threshold rather
        // than throwing, so misconfiguration can never wedge a run.
        return $count > 0 ? $count : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function safelyLog(string $level, string $message, array $context): void
    {
        try {
            $this->logger->{$level}($message, $context);
        } catch (Throwable) {
            // A hostile or misconfigured logger must not become a second failure
            // surface; mirror the SafeReporting discipline used elsewhere.
        }
    }
}
