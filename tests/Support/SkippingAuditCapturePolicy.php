<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Support;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Test CapturePolicy that returns a configurable decision per category.
 *
 * Defaults every category to CaptureDecision::Skip so feature tests can prove
 * true per-field omission; override individual categories in the constructor to
 * mix Full / Redact / Skip (mirrors SkippingMemoryCapturePolicy for the audit
 * capture surface).
 */
final class SkippingAuditCapturePolicy implements CapturePolicy
{
    public function __construct(
        private CaptureDecision $inputs = CaptureDecision::Skip,
        private CaptureDecision $outputs = CaptureDecision::Skip,
        private CaptureDecision $artifacts = CaptureDecision::Skip,
        private CaptureDecision $activeContext = CaptureDecision::Skip,
    ) {}

    public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->inputs;
    }

    public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->outputs;
    }

    public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->artifacts;
    }

    public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->activeContext;
    }
}
