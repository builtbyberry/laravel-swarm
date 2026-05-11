<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

/**
 * How parallel step guardrails interact with recording order (distinct from durable branch join policy).
 *
 * - {@see self::Existing}: validate each branch output immediately before that branch's step is recorded.
 * - {@see self::BatchValidateBeforeRecord}: (sync parallel only) validate every branch output before any
 *   `SwarmStepRecorder::completed()` call. On first violation, the run fails without recording completed steps.
 *   Durable and queued hierarchical parallel paths fall back to {@see self::Existing} (documented).
 */
enum GuardrailParallelFailurePolicy: string
{
    case Existing = 'existing';
    case BatchValidateBeforeRecord = 'batch_validate_before_record';
}
