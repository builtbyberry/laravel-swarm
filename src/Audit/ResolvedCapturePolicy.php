<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/** Immutable per-run policy snapshot used across process boundaries. @internal */
final readonly class ResolvedCapturePolicy implements CapturePolicy
{
    public function __construct(
        private CaptureDecision $inputDecision,
        private CaptureDecision $outputDecision,
    ) {}

    public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->inputDecision;
    }

    public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->outputDecision;
    }

    public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->outputDecision;
    }

    public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->inputDecision;
    }
}
