<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Declarative policy for what to capture into audit evidence and run history.
 *
 * Bind a custom implementation in the service container to make capture
 * decisions per-run rather than via the static swarm.capture.* booleans.
 * Implementations receive the RunContext and resolved Actor and return one of
 * CaptureDecision::Full | Redact | Skip per category.
 *
 * Policies never see the captured payload itself — by design, so policy
 * decisions cannot couple to payload shapes (which the v0.4 schema freeze
 * targets) and cannot leak the unredacted payload through their own code.
 *
 * The default binding (BooleanCapturePolicy) reads the existing
 * swarm.capture.* booleans and returns Full when true / Redact when false,
 * preserving today's behavior exactly. Booleans are deprecated in v0.4 and
 * scheduled for removal in v0.5.
 */
interface CapturePolicy
{
    public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;

    public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;

    public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;

    public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;
}
