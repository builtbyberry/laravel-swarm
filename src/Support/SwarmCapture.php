<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Adapter over the bound CapturePolicy.
 *
 * SwarmCapture is the single chokepoint that turns a CapturePolicy decision
 * into a captured payload shape for run history, the context store, and
 * lifecycle/stream events. Custom policies bound in the container affect every
 * emit site without touching call sites. The default BooleanCapturePolicy reads
 * the swarm.capture.* booleans and only ever returns Full / Redact, so apps
 * using those booleans see no behavior change.
 *
 * Per-field decision shapes:
 *
 *  - Full   — value as-is.
 *  - Redact — scalar values become {@see self::REDACTED}; structure/keys
 *             preserved.
 *  - Skip   — the field is omitted entirely: absent from persisted/emitted
 *             arrays and null on the DB columns that allow it (history/context
 *             step input/output, context input, top-level run output, the
 *             error `message`). Use the Skip-aware apply and persisted-array
 *             helpers to obtain that shape; the `capturesX()` predicates stay
 *             `=== Full` so the execution/storage gates they drive are
 *             unchanged.
 *
 * @internal
 */
class SwarmCapture
{
    public const REDACTED = '[redacted]';

    public function __construct(
        protected ConfigRepository $config,
        protected CapturePolicy $policy,
    ) {}

    // ------------------------------------------------------------------
    // Per-category decisions
    // ------------------------------------------------------------------

    public function inputsDecision(?RunContext $context = null): CaptureDecision
    {
        return $this->policy->inputs($context, $context?->actor());
    }

    public function outputsDecision(?RunContext $context = null): CaptureDecision
    {
        return $this->policy->outputs($context, $context?->actor());
    }

    public function artifactsDecision(?RunContext $context = null): CaptureDecision
    {
        return $this->policy->artifacts($context, $context?->actor());
    }

    public function activeContextDecision(?RunContext $context = null): CaptureDecision
    {
        return $this->policy->activeContext($context, $context?->actor());
    }

    /**
     * Failure capture is a function of inputs + outputs: Full only when both
     * are Full, Skip as soon as either category is Skip, otherwise Redact.
     */
    public function failuresDecision(?RunContext $context = null): CaptureDecision
    {
        $inputs = $this->inputsDecision($context);
        $outputs = $this->outputsDecision($context);

        if ($inputs === CaptureDecision::Full && $outputs === CaptureDecision::Full) {
            return CaptureDecision::Full;
        }

        if ($inputs === CaptureDecision::Skip || $outputs === CaptureDecision::Skip) {
            return CaptureDecision::Skip;
        }

        return CaptureDecision::Redact;
    }

    // ------------------------------------------------------------------
    // Scalar helpers
    // ------------------------------------------------------------------

    /**
     * Redact-or-keep helper retained for call sites that require a non-null
     * string (e.g. the live in-memory {@see SwarmStep} / {@see SwarmResponse}
     * surface). Skip collapses to {@see self::REDACTED} here; use
     * {@see applyInput()} where omission is required.
     */
    public function input(string $input): string
    {
        return $this->capturesInputs() ? $input : self::REDACTED;
    }

    public function output(string $output): string
    {
        return $this->capturesOutputs() ? $output : self::REDACTED;
    }

    /**
     * Skip-aware input helper for persisted/emitted payloads: Full → value,
     * Redact → REDACTED, Skip → null (caller omits the field).
     */
    public function applyInput(string $input, ?RunContext $context = null): ?string
    {
        return $this->applyScalar($input, $this->inputsDecision($context));
    }

    public function applyOutput(string $output, ?RunContext $context = null): ?string
    {
        return $this->applyScalar($output, $this->outputsDecision($context));
    }

    public function failureMessage(Throwable $exception): string
    {
        return $this->capturesFailures() ? $exception->getMessage() : self::REDACTED;
    }

    /**
     * Skip-aware failure message: Full → message, Redact → REDACTED, Skip →
     * null so the caller omits the `error.message` key while keeping `class`.
     */
    public function applyFailureMessage(Throwable $exception, ?RunContext $context = null): ?string
    {
        return $this->applyScalar($exception->getMessage(), $this->failuresDecision($context));
    }

    public function failureException(Throwable $exception): Throwable
    {
        return $this->capturesFailures() ? $exception : new SwarmException(self::REDACTED);
    }

    /**
     * Build a persisted/structured failure payload. Full/Redact keep a
     * `message`; Skip omits it (keeping `class` and any provided extras such
     * as `timed_out`). Use for JSON `failure` columns and durable detail
     * structures so Skip stays consistent with the error-message contract.
     *
     * @param  array{timed_out?: bool}  $extra
     * @return array{message?: string, class: class-string<Throwable>, timed_out?: bool}
     */
    public function failureArray(Throwable $exception, array $extra = [], ?RunContext $context = null): array
    {
        $message = $this->applyFailureMessage($exception, $context);

        if ($message === null) {
            return ['class' => $exception::class] + $extra;
        }

        return ['message' => $message, 'class' => $exception::class] + $extra;
    }

    /**
     * @param  array<int, SwarmArtifact>  $artifacts
     * @return array<int, SwarmArtifact>
     */
    public function artifacts(array $artifacts): array
    {
        return $this->capturesArtifacts() ? $artifacts : [];
    }

    // ------------------------------------------------------------------
    // Composite live objects (in-memory + Full/Redact persistence)
    // ------------------------------------------------------------------

    public function context(RunContext $context): RunContext
    {
        if ($this->capturesInputs() && $this->capturesOutputs() && $this->capturesArtifacts()) {
            return $context;
        }

        $data = $context->data;

        if (! $this->capturesInputs()) {
            $data = ['input' => self::REDACTED];
        }

        if (! $this->capturesOutputs()) {
            foreach (['last_output', 'hierarchical_node_outputs', 'durable_hierarchical_cursor'] as $key) {
                if (array_key_exists($key, $data)) {
                    $data[$key] = self::REDACTED;
                }
            }
        }

        return new RunContext(
            runId: $context->runId,
            input: $this->input($context->input),
            data: $data,
            metadata: $context->metadata,
            artifacts: $this->artifacts($context->artifacts),
        );
    }

    public function activeContext(RunContext $context): RunContext
    {
        if ($this->capturesActiveContext()) {
            return $context;
        }

        return new RunContext(
            runId: $context->runId,
            input: self::REDACTED,
            data: ['input' => self::REDACTED],
            metadata: $context->metadata,
            artifacts: [],
        );
    }

    public function terminalContext(RunContext $context): RunContext
    {
        if (! $this->capturesActiveContext()) {
            return $this->activeContext($context);
        }

        return $this->context($context);
    }

    public function response(SwarmResponse $response): SwarmResponse
    {
        if ($this->capturesInputs() && $this->capturesOutputs() && $this->capturesArtifacts()) {
            return $response;
        }

        return new SwarmResponse(
            output: $this->output($response->output),
            steps: array_map(fn (SwarmStep $step): SwarmStep => $this->step($step), $response->steps),
            usage: $response->usage,
            context: $response->context !== null ? $this->context($response->context) : null,
            artifacts: $this->artifacts($response->artifacts),
            metadata: $response->metadata,
        );
    }

    public function step(SwarmStep $step): SwarmStep
    {
        if ($this->capturesInputs() && $this->capturesOutputs() && $this->capturesArtifacts()) {
            return $step;
        }

        return new SwarmStep(
            agentClass: $step->agentClass,
            input: $this->input($step->input),
            output: $this->output($step->output),
            artifacts: $this->artifacts($step->artifacts),
            metadata: $step->metadata,
        );
    }

    // ------------------------------------------------------------------
    // Skip-aware persisted-array helpers
    // ------------------------------------------------------------------

    /**
     * Serialize a step for persistence with Skip omitting input/output keys.
     * Full keeps the value, Redact replaces scalars with REDACTED. Decoupled
     * from {@see SwarmStep::toArray()} so persistence does not depend on the
     * step always carrying `input` / `output` keys.
     *
     * @return array<string, mixed>
     */
    public function stepToPersistedArray(SwarmStep $step, ?RunContext $context = null): array
    {
        $array = $step->toArray();

        $this->applyScalarKey($array, 'input', $step->input, $this->inputsDecision($context));
        $this->applyScalarKey($array, 'output', $step->output, $this->outputsDecision($context));

        $array['artifacts'] = array_map(
            static fn (SwarmArtifact $artifact): array => $artifact->toArray(),
            $this->artifacts($step->artifacts),
        );

        return $array;
    }

    /**
     * Strip Skip-omitted keys from a serialized run-history context payload.
     * Operates on the array produced from a {@see context()} result so the
     * boolean Redact path is untouched (no Skip decision means no key removal).
     *
     * @param  array<string, mixed>  $contextArray
     * @return array<string, mixed>
     */
    public function omitSkippedHistoryContextKeys(array $contextArray, ?RunContext $context = null): array
    {
        if ($this->inputsDecision($context) === CaptureDecision::Skip) {
            unset($contextArray['input']);

            if (isset($contextArray['data']) && is_array($contextArray['data'])) {
                unset($contextArray['data']['input']);
            }
        }

        if ($this->outputsDecision($context) === CaptureDecision::Skip && isset($contextArray['data']) && is_array($contextArray['data'])) {
            foreach (['last_output', 'hierarchical_node_outputs', 'durable_hierarchical_cursor'] as $key) {
                unset($contextArray['data'][$key]);
            }
        }

        return $contextArray;
    }

    /**
     * Strip the Skip-omitted `input` key (and its data mirror) from a
     * serialized active-context payload written to the context store.
     *
     * @param  array<string, mixed>  $contextArray
     * @return array<string, mixed>
     */
    public function omitSkippedActiveContextKeys(array $contextArray, ?RunContext $context = null): array
    {
        if ($this->activeContextDecision($context) === CaptureDecision::Skip) {
            unset($contextArray['input']);

            if (isset($contextArray['data']) && is_array($contextArray['data'])) {
                unset($contextArray['data']['input']);
            }
        }

        return $contextArray;
    }

    // ------------------------------------------------------------------
    // Full-gated predicates (execution / storage gates — unchanged)
    // ------------------------------------------------------------------

    public function capturesInputs(): bool
    {
        return $this->policy->inputs() === CaptureDecision::Full;
    }

    public function capturesOutputs(): bool
    {
        return $this->policy->outputs() === CaptureDecision::Full;
    }

    public function capturesArtifacts(): bool
    {
        return $this->policy->artifacts() === CaptureDecision::Full;
    }

    public function capturesActiveContext(): bool
    {
        return $this->policy->activeContext() === CaptureDecision::Full;
    }

    public function capturesFailures(): bool
    {
        return $this->capturesInputs() && $this->capturesOutputs();
    }

    protected function applyScalar(string $value, CaptureDecision $decision): ?string
    {
        return match ($decision) {
            CaptureDecision::Full => $value,
            CaptureDecision::Redact => self::REDACTED,
            CaptureDecision::Skip => null,
        };
    }

    /**
     * @param  array<string, mixed>  $array
     */
    protected function applyScalarKey(array &$array, string $key, string $value, CaptureDecision $decision): void
    {
        $applied = $this->applyScalar($value, $decision);

        if ($applied === null) {
            unset($array[$key]);

            return;
        }

        $array[$key] = $applied;
    }
}
